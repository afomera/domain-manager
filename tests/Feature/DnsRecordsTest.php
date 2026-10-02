<?php

namespace Tests\Feature;

use App\Enums\DnsRecordType;
use App\Models\CloudflareConnection;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class DnsRecordsTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->domain = Domain::factory()->create(['name' => 'example.dev']);
    }

    private function dnsRecords(): Testable
    {
        return Livewire::actingAs($this->domain->user)->test('domains.dns-records', ['domain' => $this->domain]);
    }

    public function test_it_adds_a_record_and_normalizes_the_name(): void
    {
        $this->dnsRecords()
            ->call('create')
            ->set('form.type', 'CNAME')
            ->set('form.name', 'blog.example.dev')
            ->set('form.content', 'target.example.com')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('dns-records-changed')
            ->assertDispatched('toast', message: 'Added CNAME record blog');

        $this->assertDatabaseHas('dns_records', [
            'domain_id' => $this->domain->id,
            'type' => 'CNAME',
            'name' => 'blog',
            'content' => 'target.example.com',
            'ttl' => 1,
            'proxied' => true,
        ]);
    }

    public function test_the_bare_domain_name_becomes_the_apex(): void
    {
        $this->dnsRecords()
            ->call('create')
            ->set('form.name', 'example.dev')
            ->set('form.content', '192.0.2.10')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('@', $this->domain->dnsRecords()->sole()->name);
    }

    public function test_it_validates_content_for_the_record_type(): void
    {
        $this->dnsRecords()
            ->call('create')
            ->set('form.name', '@')
            ->set('form.content', '999.1.1.1')
            ->call('save')
            ->assertHasErrors(['form.content' => 'ipv4']);

        $this->dnsRecords()
            ->call('create')
            ->set('form.type', 'MX')
            ->set('form.name', '@')
            ->set('form.content', 'mail.example.com')
            ->set('form.priority', '70000')
            ->call('save')
            ->assertHasErrors(['form.priority']);

        $this->assertSame(0, $this->domain->dnsRecords()->count());
    }

    public function test_a_cname_cannot_share_a_name_with_other_records(): void
    {
        DnsRecord::factory()->for($this->domain)->create(['name' => 'www']);

        $this->dnsRecords()
            ->call('create')
            ->set('form.type', 'CNAME')
            ->set('form.name', 'WWW')
            ->set('form.content', 'target.example.com')
            ->call('save')
            ->assertHasErrors(['form.name']);
    }

    public function test_other_records_cannot_be_added_beside_a_cname(): void
    {
        DnsRecord::factory()->for($this->domain)->cname('www', 'target.example.com')->create();

        $this->dnsRecords()
            ->call('create')
            ->set('form.type', 'TXT')
            ->set('form.name', 'www')
            ->set('form.content', 'hello')
            ->call('save')
            ->assertHasErrors(['form.name']);
    }

    public function test_switching_to_a_non_proxyable_type_turns_off_the_proxy(): void
    {
        $this->dnsRecords()
            ->call('create')
            ->set('form.type', 'TXT')
            ->assertSet('form.proxied', false);
    }

    public function test_it_updates_a_record(): void
    {
        $record = DnsRecord::factory()->for($this->domain)->create(['content' => '192.0.2.10']);

        $this->dnsRecords()
            ->call('edit', $record->id)
            ->assertSet('form.content', '192.0.2.10')
            ->set('form.type', 'MX')
            ->set('form.content', 'mx.example.com')
            ->set('form.priority', '5')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', null);

        $record->refresh();
        $this->assertSame(DnsRecordType::MX, $record->type);
        $this->assertSame(5, $record->priority);
        $this->assertFalse($record->proxied);
    }

    public function test_it_deletes_a_record(): void
    {
        $record = DnsRecord::factory()->for($this->domain)->create();

        $this->dnsRecords()
            ->call('edit', $record->id)
            ->set('confirmingDelete', true)
            ->call('delete')
            ->assertDispatched('toast');

        $this->assertModelMissing($record);
    }

    public function test_records_from_another_domain_cannot_be_edited(): void
    {
        $foreignRecord = DnsRecord::factory()->create();

        $this->dnsRecords()
            ->call('edit', $foreignRecord->id)
            ->assertNotFound();
    }

    public function test_other_users_cannot_change_records(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('domains.dns-records', ['domain' => $this->domain])
            ->call('create')
            ->assertForbidden();
    }

    public function test_read_only_types_cannot_be_opened_for_editing(): void
    {
        $record = DnsRecord::factory()->for($this->domain)->create(['type' => 'CAA', 'content' => '0 issue "letsencrypt.org"', 'proxied' => false]);

        $this->dnsRecords()
            ->call('edit', $record->id)
            ->assertSet('editing', null);
    }

    public function test_changes_to_a_cloudflare_zone_go_through_the_api(): void
    {
        $this->linkToCloudflare();

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone_1/dns_records' => Http::response($this->cloudflareResponse([
                'id' => 'rec_new', 'type' => 'A', 'name' => 'app.example.dev', 'content' => '192.0.2.20', 'ttl' => 1, 'proxied' => true,
            ])),
        ]);

        $this->dnsRecords()
            ->call('create')
            ->set('form.name', 'app')
            ->set('form.content', '192.0.2.20')
            ->call('save')
            ->assertHasNoErrors();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['name'] === 'app.example.dev'
            && $request['content'] === '192.0.2.20'
            && $request['proxied'] === true
            && $request->hasHeader('Authorization', 'Bearer cf-test-token-1234567890'));

        $this->assertDatabaseHas('dns_records', ['domain_id' => $this->domain->id, 'cloudflare_id' => 'rec_new', 'name' => 'app']);
    }

    public function test_updates_and_deletes_use_the_cloudflare_record_id(): void
    {
        $this->linkToCloudflare();
        $record = DnsRecord::factory()->for($this->domain)->mx('mx1.example.com')->create(['cloudflare_id' => 'rec_mx']);

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone_1/dns_records/rec_mx' => Http::sequence()
                ->push($this->cloudflareResponse([
                    'id' => 'rec_mx', 'type' => 'MX', 'name' => 'example.dev', 'content' => 'mx2.example.com', 'ttl' => 3600, 'priority' => 20, 'proxied' => false,
                ]))
                ->push($this->cloudflareResponse(['id' => 'rec_mx'])),
        ]);

        $this->dnsRecords()
            ->call('edit', $record->id)
            ->set('form.content', 'mx2.example.com')
            ->set('form.priority', '20')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('mx2.example.com', $record->fresh()->content);

        $this->dnsRecords()
            ->call('edit', $record->id)
            ->call('delete');

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request['priority'] === 20);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
        $this->assertModelMissing($record);
    }

    public function test_cloudflare_errors_are_shown_and_nothing_is_saved(): void
    {
        $this->linkToCloudflare();

        Http::fake([
            'api.cloudflare.com/*' => Http::response($this->cloudflareError('Record already exists.', 81057), 400),
        ]);

        $this->dnsRecords()
            ->call('create')
            ->set('form.name', 'www')
            ->set('form.content', '192.0.2.20')
            ->call('save')
            ->assertHasErrors(['form.cloudflare']);

        $this->assertSame(0, $this->domain->dnsRecords()->count());
    }

    private function linkToCloudflare(): void
    {
        $this->domain->update(['cloudflare_zone_id' => 'zone_1', 'cloudflare_zone_status' => 'active']);
        CloudflareConnection::factory()->for($this->domain->user)->create(['api_token' => 'cf-test-token-1234567890']);
        $this->domain->unsetRelation('user');
    }
}
