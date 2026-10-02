<?php

namespace App\Services\Cloudflare;

use App\Enums\DnsRecordType;
use App\Enums\Provider;
use App\Events\PortfolioUpdated;
use App\Models\CloudflareConnection;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Services\Rdap\RdapClient;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Pulls a user's domains, zones and DNS records from Cloudflare into the local database.
 * Cloudflare is the source of truth for anything it knows; RDAP fills in domains registered elsewhere.
 */
class CloudflareSync
{
    /**
     * The domain-check endpoint accepts at most this many names per request.
     */
    protected const int DOMAIN_CHECK_LIMIT = 20;

    /**
     * @var list<string>
     */
    protected array $warnings = [];

    /**
     * @var (Closure(string, float): void)|null
     */
    protected ?Closure $progress = null;

    public function __construct(protected RdapClient $rdap) {}

    /**
     * Run a sync while recording its status and progress on the connection, so the UI can follow along.
     *
     * @return array{domains: int, records: int, warnings: list<string>}
     *
     * @throws CloudflareException
     */
    public function runTracked(CloudflareConnection $connection): array
    {
        $connection->update([
            'sync_status' => 'running',
            'sync_progress' => ['message' => 'Starting…', 'percent' => 0],
            'sync_started_at' => now(),
        ]);
        PortfolioUpdated::dispatch($connection->user_id);

        try {
            return $this->run($connection, function (string $message, float $percent) use ($connection) {
                $connection->update(['sync_progress' => ['message' => $message, 'percent' => (int) floor($percent)]]);
            });
        } finally {
            $connection->update(['sync_status' => null, 'sync_progress' => null]);
            PortfolioUpdated::dispatch($connection->user_id);
        }
    }

    /**
     * @param  (Closure(string, float): void)|null  $progress  receives a step description and overall percent complete
     * @return array{domains: int, records: int, warnings: list<string>}
     *
     * @throws CloudflareException
     */
    public function run(CloudflareConnection $connection, ?Closure $progress = null): array
    {
        $this->warnings = [];
        $this->progress = $progress;
        $client = $connection->client();
        $syncedAt = CarbonImmutable::now();

        try {
            $this->report('Fetching zones', 0);
            $zones = collect($client->zones())->keyBy(fn (array $zone) => strtolower($zone['name']));

            $this->report('Reading Registrar data', 5);
            $registrations = $this->fetchRegistrations($client, $this->accounts($client, $zones->all()));

            foreach ($registrations as $name => [$accountId, $registration]) {
                $this->syncRegistration($connection, $name, $accountId, $registration, $syncedAt);
            }

            $this->report('Checking renewal prices', 15);
            $this->applyRenewalPrices($client, $connection, $registrations);

            $recordCount = 0;
            foreach ($zones->values() as $index => $zone) {
                $this->report("Importing DNS records for {$zone['name']}", 20 + 55 * $index / max(1, $zones->count()));
                $domain = $this->syncZone($connection, strtolower($zone['name']), $zone, $syncedAt);
                $recordCount += $this->syncRecords($client, $domain, $zone['id']);
            }

            $this->detachRemovedZones($connection, $zones->keys()->all());
            $this->lookUpExternalRegistrations($connection, array_keys($registrations), $syncedAt);
            $this->report('Finishing up', 100);
        } catch (CloudflareException $exception) {
            $connection->update(['last_sync_error' => $exception->getMessage()]);

            throw $exception;
        }

        $connection->update([
            'verified_at' => $syncedAt,
            'last_synced_at' => $syncedAt,
            'last_sync_error' => $this->warnings ? implode(' ', $this->warnings) : null,
        ]);

        return [
            'domains' => $connection->user->domains()->count(),
            'records' => $recordCount,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param  float  $percent  overall progress, 0–100
     */
    protected function report(string $message, float $percent): void
    {
        if ($this->progress) {
            ($this->progress)($message, min(100, $percent));
        }
    }

    /**
     * Accounts the token can see. Tokens without account-level read access fall back to the accounts that own its zones.
     *
     * @param  array<string, array<string, mixed>>  $zones
     * @return list<array{id: string, name: string}>
     */
    protected function accounts(CloudflareClient $client, array $zones): array
    {
        try {
            return $client->accounts();
        } catch (CloudflareException $exception) {
            if (! $exception->isPermissionError()) {
                throw $exception;
            }

            return collect($zones)->pluck('account')->filter()->unique('id')->values()->all();
        }
    }

    /**
     * Registrar data across the given accounts, keyed by domain name.
     *
     * @param  list<array{id: string, name: string}>  $accounts
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    protected function fetchRegistrations(CloudflareClient $client, array $accounts): array
    {
        $registrations = [];

        foreach ($accounts as $account) {
            try {
                foreach ($client->registrations($account['id']) as $registration) {
                    $registrations[strtolower($registration['domain_name'])] = [$account['id'], $registration];
                }
            } catch (CloudflareException $exception) {
                if (! $exception->isPermissionError()) {
                    throw $exception;
                }

                $this->warnings[] = "The token can’t read Registrar data for “{$account['name']}”, so expiry and auto‑renew weren’t updated.";
            }
        }

        return $registrations;
    }

    /**
     * @param  array<string, mixed>  $registration
     */
    protected function syncRegistration(CloudflareConnection $connection, string $name, string $accountId, array $registration, CarbonImmutable $syncedAt): void
    {
        $domain = $connection->user->domains()->firstOrNew(['name' => $name]);

        $domain->fill([
            'registrar' => Provider::Cloudflare,
            'registrar_name' => null,
            'registration_status' => $registration['status'] ?? null,
            'registered_on' => isset($registration['created_at']) ? CarbonImmutable::parse($registration['created_at']) : $domain->registered_on,
            'expires_on' => isset($registration['expires_at']) ? CarbonImmutable::parse($registration['expires_at']) : $domain->expires_on,
            'auto_renew' => (bool) ($registration['auto_renew'] ?? false),
            'cloudflare_account_id' => $accountId,
            'synced_at' => $syncedAt,
        ]);

        // Cloudflare Registrar requires Cloudflare nameservers.
        $domain->nameserver_provider = Provider::Cloudflare;
        $domain->save();
    }

    /**
     * Cloudflare Registrar sells at cost, so every standard domain on a TLD renews at the same price.
     * The API only prices names that are still available, so we price each TLD by checking a random unregistered name.
     * Prices set by hand (e.g. for premium domains) are left alone.
     *
     * @param  array<string, array{0: string, 1: array<string, mixed>}>  $registrations
     */
    protected function applyRenewalPrices(CloudflareClient $client, CloudflareConnection $connection, array $registrations): void
    {
        if ($registrations === []) {
            return;
        }

        $accountId = reset($registrations)[0];
        $suffixes = collect(array_keys($registrations))->map(fn (string $name) => Str::after($name, '.'))->unique();
        $uncached = $suffixes->reject(fn (string $suffix) => Cache::has(self::priceCacheKey($suffix)));

        try {
            foreach ($uncached->chunk(self::DOMAIN_CHECK_LIMIT) as $chunk) {
                $probes = $chunk->mapWithKeys(fn (string $suffix) => ['dcc-price-'.Str::lower(Str::random(12)).'.'.$suffix => $suffix]);

                foreach ($client->checkDomains($accountId, $probes->keys()->all()) as $result) {
                    $suffix = $probes->get(strtolower($result['name'] ?? ''));
                    $pricing = $result['pricing'] ?? null;

                    if ($suffix === null) {
                        continue;
                    }

                    // Only USD is supported for display; anything else is treated as unknown.
                    $cents = ($result['registrable'] ?? false) && ($pricing['currency'] ?? null) === 'USD'
                        ? (int) round((float) $pricing['renewal_cost'] * 100)
                        : null;

                    Cache::put(self::priceCacheKey($suffix), ['cents' => $cents], now()->addWeek());
                }
            }
        } catch (CloudflareException $exception) {
            $this->warnings[] = 'Couldn’t look up Cloudflare renewal prices: '.$exception->getMessage();
        }

        foreach ($suffixes as $suffix) {
            $cents = Cache::get(self::priceCacheKey($suffix))['cents'] ?? null;

            if ($cents === null) {
                continue;
            }

            $namesWithSuffix = array_filter(array_keys($registrations), fn (string $name) => Str::after($name, '.') === $suffix);

            $connection->user->domains()
                ->whereIn('name', $namesWithSuffix)
                ->where(fn ($query) => $query->whereNull('renewal_price_source')->orWhere('renewal_price_source', Domain::PRICE_FROM_CLOUDFLARE))
                ->update(['renewal_price_cents' => $cents, 'renewal_price_source' => Domain::PRICE_FROM_CLOUDFLARE]);
        }
    }

    public static function priceCacheKey(string $suffix): string
    {
        return 'cloudflare-renewal-price:'.strtolower($suffix);
    }

    /**
     * @param  array<string, mixed>  $zone
     */
    protected function syncZone(CloudflareConnection $connection, string $name, array $zone, CarbonImmutable $syncedAt): Domain
    {
        $domain = $connection->user->domains()->firstOrNew(['name' => $name]);

        if (! $domain->exists) {
            $domain->registrar = Provider::Other;
        }

        $domain->fill([
            'cloudflare_account_id' => $domain->cloudflare_account_id ?? ($zone['account']['id'] ?? null),
            'cloudflare_zone_id' => $zone['id'],
            'cloudflare_zone_status' => $zone['status'] ?? null,
            'nameservers' => $zone['name_servers'] ?? null,
            'synced_at' => $syncedAt,
        ]);

        // An active zone means the domain's nameservers point at Cloudflare.
        $domain->nameserver_provider = ($zone['status'] ?? null) === 'active' ? Provider::Cloudflare : $domain->registrar;
        $domain->save();

        return $domain;
    }

    protected function syncRecords(CloudflareClient $client, Domain $domain, string $zoneId): int
    {
        $syncedIds = [];

        foreach ($client->dnsRecords($zoneId) as $remote) {
            $type = DnsRecordType::tryFrom($remote['type'] ?? '');

            if (! $type) {
                continue;
            }

            $domain->dnsRecords()->updateOrCreate(
                ['cloudflare_id' => $remote['id']],
                self::recordAttributes($remote, $type, $domain->name),
            );

            $syncedIds[] = $remote['id'];
        }

        $domain->dnsRecords()
            ->where(fn ($query) => $query->whereNull('cloudflare_id')->orWhereNotIn('cloudflare_id', $syncedIds))
            ->delete();

        return count($syncedIds);
    }

    /**
     * Map a Cloudflare DNS record onto local attributes.
     *
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    public static function recordAttributes(array $remote, DnsRecordType $type, string $zoneName): array
    {
        return [
            'type' => $type,
            'name' => DnsRecord::relativeName($remote['name'], $zoneName),
            'content' => (string) ($remote['content'] ?? ''),
            'ttl' => (int) ($remote['ttl'] ?? 1),
            'proxied' => (bool) ($remote['proxied'] ?? false),
            'priority' => isset($remote['priority']) ? (int) $remote['priority'] : null,
        ];
    }

    /**
     * Zones deleted in Cloudflare: forget the zone and its records.
     *
     * @param  list<string>  $zoneNames
     */
    protected function detachRemovedZones(CloudflareConnection $connection, array $zoneNames): void
    {
        $connection->user->domains()
            ->whereNotNull('cloudflare_zone_id')
            ->whereNotIn('name', $zoneNames)
            ->each(function (Domain $domain) {
                $domain->dnsRecords()->whereNotNull('cloudflare_id')->delete();
                $domain->update([
                    'cloudflare_zone_id' => null,
                    'cloudflare_zone_status' => null,
                    'nameservers' => null,
                    'nameserver_provider' => $domain->registrar,
                ]);
            });
    }

    /**
     * Fill registrar and dates from RDAP for domains Cloudflare didn't report as registered with it.
     *
     * @param  list<string>  $registeredWithCloudflare
     */
    protected function lookUpExternalRegistrations(CloudflareConnection $connection, array $registeredWithCloudflare, CarbonImmutable $syncedAt): void
    {
        $domains = $connection->user->domains()->whereNotIn('name', $registeredWithCloudflare)->get();

        $domains->each(function (Domain $domain, int $index) use ($syncedAt, $domains) {
            $this->report("Looking up the registrar for {$domain->name}", 75 + 25 * $index / max(1, $domains->count()));

            $registration = $this->rdap->lookup($domain->name);

            if (! $registration) {
                return;
            }

            if ($registration->registrarName) {
                $domain->registrar = Provider::fromRegistrarName($registration->registrarName);
                $domain->registrar_name = $registration->registrarName;
            }

            $domain->registered_on = $registration->registeredOn ?? $domain->registered_on;
            $domain->expires_on = $registration->expiresOn ?? $domain->expires_on;
            $domain->synced_at = $syncedAt;

            if (! $domain->hasCloudflareZone() || $domain->cloudflare_zone_status !== 'active') {
                $domain->nameserver_provider = $domain->registrar;
            }

            $domain->save();
        });
    }
}
