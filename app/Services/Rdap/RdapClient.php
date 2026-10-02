<?php

namespace App\Services\Rdap;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Looks up registrar and expiry dates over RDAP (the successor to WHOIS).
 * Used for domains whose registrar has no API, like Squarespace.
 */
class RdapClient
{
    /**
     * rdap.org redirects each query to the authoritative registry's RDAP server.
     */
    public const string BASE_URL = 'https://rdap.org';

    /**
     * Best-effort: returns null when the TLD has no RDAP service or the lookup fails.
     */
    public function lookup(string $domainName): ?RdapRegistration
    {
        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->accept('application/rdap+json, application/json')
                ->timeout(10)
                ->get('domain/'.rawurlencode($domainName));
        } catch (ConnectionException) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $events = collect($response->json('events') ?? [])->pluck('eventDate', 'eventAction');

        return new RdapRegistration(
            registrarName: $this->registrarName($response->json('entities') ?? []),
            registeredOn: $this->date($events->get('registration')),
            expiresOn: $this->date($events->get('expiration')),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     */
    protected function registrarName(array $entities): ?string
    {
        $registrar = collect($entities)->first(fn (array $entity) => in_array('registrar', $entity['roles'] ?? [], true));

        // vCard properties look like ["fn", {}, "text", "Squarespace Domains II LLC"].
        $formattedName = collect($registrar['vcardArray'][1] ?? [])->first(fn ($property) => ($property[0] ?? null) === 'fn');

        return $formattedName[3] ?? null;
    }

    protected function date(?string $value): ?CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
