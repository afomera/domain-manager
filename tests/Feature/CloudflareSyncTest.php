<?php

namespace Tests\Feature;

use App\Enums\DnsRecordType;
use App\Enums\Provider;
use App\Models\CloudflareConnection;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Services\Cloudflare\CloudflareException;
use App\Services\Cloudflare\CloudflareSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareSyncTest extends TestCase
{
    use RefreshDatabase;

    private CloudflareConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = CloudflareConnection::factory()->create();
    }

    public function test_it_imports_registrations_zones_and_records(): void
    {
        $this->fakeCloudflare();

        $result = app(CloudflareSync::class)->run($this->connection);

        $this->assertSame(2, $result['domains']);
        $this->assertSame(4, $result['records'], 'The unsupported record type is skipped.');

        $registered = $this->connection->user->domains()->firstWhere('name', 'harborlight.dev');
        $this->assertSame(Provider::Cloudflare, $registered->registrar);
        $this->assertSame(Provider::Cloudflare, $registered->nameserver_provider);
        $this->assertSame('2027-03-14', $registered->expires_on->toDateString());
        $this->assertTrue($registered->auto_renew);
        $this->assertSame('acc_1', $registered->cloudflare_account_id);
        $this->assertSame('zone_harbor', $registered->cloudflare_zone_id);
        $this->assertSame(['ada.ns.cloudflare.com', 'kurt.ns.cloudflare.com'], $registered->nameservers);

        $records = $registered->dnsRecords()->orderBy('name')->get();
        $this->assertSame(['@', 'www'], $records->pluck('name')->unique()->values()->all());
        $mx = $records->firstWhere('type', DnsRecordType::MX);
        $this->assertSame(10, $mx->priority);

        // Zone-only domain: registrar comes from RDAP.
        $external = $this->connection->user->domains()->firstWhere('name', 'maplecourt.org');
        $this->assertSame(Provider::Squarespace, $external->registrar);
        $this->assertSame('Squarespace Domains II LLC', $external->registrar_name);
        $this->assertSame(Provider::Cloudflare, $external->nameserver_provider);
        $this->assertSame('2027-01-22', $external->expires_on->toDateString());
        $this->assertSame('Ready to transfer', $external->statusNote());

        $this->connection->refresh();
        $this->assertNotNull($this->connection->last_synced_at);
        $this->assertNull($this->connection->last_sync_error);
    }

    public function test_records_removed_in_cloudflare_are_removed_locally(): void
    {
        $domain = Domain::factory()->for($this->connection->user)->create(['name' => 'harborlight.dev']);
        $stale = DnsRecord::factory()->for($domain)->create(['cloudflare_id' => 'rec_gone']);
        $localOnly = DnsRecord::factory()->for($domain)->create(['cloudflare_id' => null]);

        $this->fakeCloudflare();
        app(CloudflareSync::class)->run($this->connection);

        $this->assertModelMissing($stale);
        $this->assertModelMissing($localOnly);
        $this->assertSame(3, $domain->dnsRecords()->count());
    }

    public function test_resyncing_updates_records_in_place(): void
    {
        $this->fakeCloudflare();
        app(CloudflareSync::class)->run($this->connection);
        $firstIds = DnsRecord::query()->orderBy('id')->pluck('id');

        app(CloudflareSync::class)->run($this->connection);

        $this->assertEquals($firstIds, DnsRecord::query()->orderBy('id')->pluck('id'));
    }

    public function test_domains_deleted_from_cloudflare_lose_their_zone(): void
    {
        $domain = Domain::factory()->for($this->connection->user)->syncedFromCloudflare()->onSquarespace()->create(['name' => 'oldzone.com']);
        $domain->update(['nameserver_provider' => Provider::Cloudflare]);
        DnsRecord::factory()->for($domain)->create(['cloudflare_id' => 'rec_old']);

        $this->fakeCloudflare();
        app(CloudflareSync::class)->run($this->connection);

        $domain->refresh();
        $this->assertNull($domain->cloudflare_zone_id);
        $this->assertSame(0, $domain->dnsRecords()->count());
    }

    public function test_missing_registrar_permission_is_a_warning_not_a_failure(): void
    {
        $this->fakeCloudflare(registrations: Http::response($this->cloudflareError('Authentication error', 10000), 403));

        $result = app(CloudflareSync::class)->run($this->connection);

        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('Registrar', $this->connection->fresh()->last_sync_error);
        $this->assertSame(2, $result['domains']);
    }

    public function test_an_invalid_token_records_the_error(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->cloudflareError('Invalid API Token', 1000), 401)]);

        try {
            app(CloudflareSync::class)->run($this->connection);
            $this->fail('Expected the sync to fail.');
        } catch (CloudflareException $exception) {
            $this->assertStringContainsString('Invalid API Token', $exception->getMessage());
        }

        $this->assertStringContainsString('Invalid API Token', $this->connection->fresh()->last_sync_error);
    }

    public function test_the_sync_command_runs_for_every_connection(): void
    {
        $this->fakeCloudflare();

        $this->artisan('cloudflare:sync')->assertSuccessful();

        $this->assertSame(2, $this->connection->user->domains()->count());
    }

    public function test_the_stored_token_is_encrypted_and_sent_as_a_bearer_token(): void
    {
        $this->connection->update(['api_token' => 'cf-secret-token-abcdefghijklmnop']);
        $this->fakeCloudflare();

        app(CloudflareSync::class)->run($this->connection);

        $this->assertDatabaseMissing('cloudflare_connections', ['api_token' => 'cf-secret-token-abcdefghijklmnop']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer cf-secret-token-abcdefghijklmnop'));
    }

    private function fakeCloudflare(mixed $registrations = null, mixed $domainCheck = null): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/accounts/acc_1/registrar/domain-check' => $domainCheck ?? fn (Request $request) => Http::response($this->cloudflareResponse([
                'domains' => collect($request['domains'])->map(fn (string $name) => [
                    'name' => $name,
                    'registrable' => true,
                    'tier' => 'standard',
                    'pricing' => ['currency' => 'USD', 'registration_cost' => '12.20', 'renewal_cost' => '12.20'],
                ])->all(),
            ])),
            'api.cloudflare.com/client/v4/accounts?*' => Http::response($this->cloudflareResponse([['id' => 'acc_1', 'name' => 'Personal']])),
            'api.cloudflare.com/client/v4/accounts/acc_1/registrar/registrations*' => $registrations ?? Http::response($this->cloudflareResponse([
                ['domain_name' => 'harborlight.dev', 'auto_renew' => true, 'created_at' => '2021-03-14T10:00:00Z', 'expires_at' => '2027-03-14T10:00:00Z', 'locked' => true, 'status' => 'active'],
            ], ['count' => 1, 'cursor' => '', 'per_page' => 50])),
            'api.cloudflare.com/client/v4/zones?*' => Http::response($this->cloudflareResponse([
                ['id' => 'zone_harbor', 'name' => 'harborlight.dev', 'status' => 'active', 'name_servers' => ['ada.ns.cloudflare.com', 'kurt.ns.cloudflare.com'], 'account' => ['id' => 'acc_1', 'name' => 'Personal']],
                ['id' => 'zone_maple', 'name' => 'maplecourt.org', 'status' => 'active', 'name_servers' => ['ada.ns.cloudflare.com', 'kurt.ns.cloudflare.com'], 'account' => ['id' => 'acc_1', 'name' => 'Personal']],
            ])),
            'api.cloudflare.com/client/v4/zones/zone_harbor/dns_records*' => Http::response($this->cloudflareResponse([
                ['id' => 'rec_a', 'type' => 'A', 'name' => 'harborlight.dev', 'content' => '76.76.21.21', 'ttl' => 1, 'proxied' => true],
                ['id' => 'rec_www', 'type' => 'CNAME', 'name' => 'www.harborlight.dev', 'content' => 'harborlight.dev', 'ttl' => 1, 'proxied' => true],
                ['id' => 'rec_mx', 'type' => 'MX', 'name' => 'harborlight.dev', 'content' => 'in1-smtp.messagingengine.com', 'ttl' => 3600, 'priority' => 10, 'proxied' => false],
                ['id' => 'rec_odd', 'type' => 'WEIRD', 'name' => 'harborlight.dev', 'content' => '?', 'ttl' => 1],
            ])),
            'api.cloudflare.com/client/v4/zones/zone_maple/dns_records*' => Http::response($this->cloudflareResponse([
                ['id' => 'rec_maple', 'type' => 'A', 'name' => 'maplecourt.org', 'content' => '192.0.2.1', 'ttl' => 300, 'proxied' => false],
            ])),
            'rdap.org/domain/maplecourt.org' => Http::response([
                'events' => [
                    ['eventAction' => 'registration', 'eventDate' => '2017-01-22T00:00:00Z'],
                    ['eventAction' => 'expiration', 'eventDate' => '2027-01-22T00:00:00Z'],
                ],
                'entities' => [
                    ['roles' => ['registrar'], 'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', 'Squarespace Domains II LLC']]]],
                ],
            ]),
            'rdap.org/*' => Http::response(null, 404),
        ]);
    }

    public function test_renewal_prices_come_from_cloudflare_at_cost_pricing(): void
    {
        $this->fakeCloudflare();

        app(CloudflareSync::class)->run($this->connection);

        $domain = $this->connection->user->domains()->firstWhere('name', 'harborlight.dev');
        $this->assertSame(1220, $domain->renewal_price_cents);
        $this->assertSame(Domain::PRICE_FROM_CLOUDFLARE, $domain->renewal_price_source);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'registrar/domain-check')
            && count($request['domains']) === 1
            && str_ends_with($request['domains'][0], '.dev')
            && $request['domains'][0] !== 'harborlight.dev');

        $this->assertNull($this->connection->user->domains()->firstWhere('name', 'maplecourt.org')->renewal_price_cents, 'Only Cloudflare-registered domains are priced.');
    }

    public function test_prices_set_by_hand_are_not_overwritten(): void
    {
        Domain::factory()->for($this->connection->user)->create([
            'name' => 'harborlight.dev',
            'renewal_price_cents' => 9900,
            'renewal_price_source' => Domain::PRICE_SET_MANUALLY,
        ]);
        $this->fakeCloudflare();

        app(CloudflareSync::class)->run($this->connection);

        $this->assertSame(9900, $this->connection->user->domains()->firstWhere('name', 'harborlight.dev')->renewal_price_cents);
    }

    public function test_tld_prices_are_cached_between_syncs(): void
    {
        $this->fakeCloudflare();

        app(CloudflareSync::class)->run($this->connection);
        app(CloudflareSync::class)->run($this->connection);

        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), 'registrar/domain-check'))->count());
    }

    public function test_a_pricing_failure_is_only_a_warning(): void
    {
        $this->fakeCloudflare(domainCheck: Http::response($this->cloudflareError('Forbidden', 10000), 403));

        $result = app(CloudflareSync::class)->run($this->connection);

        $this->assertStringContainsString('renewal prices', implode(' ', $result['warnings']));
        $this->assertNull($this->connection->user->domains()->firstWhere('name', 'harborlight.dev')->renewal_price_cents);
    }
}
