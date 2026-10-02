<?php

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Cloudflare v4 REST API.
 *
 * @see https://developers.cloudflare.com/api/
 */
class CloudflareClient
{
    public const string BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function __construct(private readonly string $token) {}

    /**
     * Confirm the token is valid and active. Handles both user-owned and account-owned tokens.
     *
     * @return array{id?: string, status?: string, expires_on?: string, not_before?: string}
     *
     * @throws CloudflareException
     */
    public function verifyToken(): array
    {
        try {
            return $this->send('get', 'user/tokens/verify')['result'] ?? [];
        } catch (CloudflareException $userTokenError) {
            // Account-owned tokens can't use the user endpoint; try each account it can see.
        }

        try {
            $accounts = $this->accounts();
        } catch (CloudflareException) {
            throw $userTokenError;
        }

        foreach ($accounts as $account) {
            try {
                return $this->send('get', "accounts/{$account['id']}/tokens/verify")['result'] ?? [];
            } catch (CloudflareException) {
                continue;
            }
        }

        throw $userTokenError;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function accounts(): array
    {
        return $this->paginate('accounts', perPage: 50);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function zones(): array
    {
        return $this->paginate('zones', perPage: 50);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dnsRecords(string $zoneId): array
    {
        return $this->paginate("zones/{$zoneId}/dns_records", perPage: 1000);
    }

    /**
     * Domains registered with Cloudflare Registrar in an account.
     *
     * @return list<array{domain_name: string, auto_renew: bool, created_at: string, expires_at: string, locked: bool, status: string}>
     */
    public function registrations(string $accountId): array
    {
        $registrations = [];
        $cursor = null;

        do {
            $body = $this->send('get', "accounts/{$accountId}/registrar/registrations", array_filter([
                'per_page' => 50,
                'cursor' => $cursor,
            ]));

            array_push($registrations, ...($body['result'] ?? []));
            $cursor = $body['result_info']['cursor'] ?? null;
        } while ($cursor);

        return $registrations;
    }

    /**
     * Availability and at-cost pricing for up to 20 domain names. Pricing is only returned for registrable names.
     *
     * @param  list<string>  $domainNames
     * @return list<array{name: string, registrable: bool, pricing?: array{currency: string, registration_cost: string, renewal_cost: string}, tier?: string, reason?: string}>
     */
    public function checkDomains(string $accountId, array $domainNames): array
    {
        return $this->send('post', "accounts/{$accountId}/registrar/domain-check", ['domains' => array_values($domainNames)])['result']['domains'] ?? [];
    }

    /**
     * @throws CloudflareException
     */
    public function setAutoRenew(string $accountId, string $domainName, bool $autoRenew): void
    {
        $body = $this->send('patch', "accounts/{$accountId}/registrar/registrations/{$domainName}", ['auto_renew' => $autoRenew]);

        if (($body['result']['state'] ?? null) === 'failed') {
            throw new CloudflareException($body['result']['error']['message'] ?? 'Cloudflare could not update auto-renew.');
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function createDnsRecord(string $zoneId, array $record): array
    {
        return $this->send('post', "zones/{$zoneId}/dns_records", $record)['result'];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function updateDnsRecord(string $zoneId, string $recordId, array $record): array
    {
        return $this->send('patch', "zones/{$zoneId}/dns_records/{$recordId}", $record)['result'];
    }

    public function deleteDnsRecord(string $zoneId, string $recordId): void
    {
        $this->send('delete', "zones/{$zoneId}/dns_records/{$recordId}");
    }

    /**
     * Fetch every page of a page-numbered list endpoint.
     *
     * @return list<array<string, mixed>>
     */
    protected function paginate(string $path, int $perPage): array
    {
        $results = [];
        $page = 1;

        do {
            $body = $this->send('get', $path, ['page' => $page, 'per_page' => $perPage]);

            array_push($results, ...($body['result'] ?? []));
            $totalPages = $body['result_info']['total_pages'] ?? 1;
            $page++;
        } while ($page <= $totalPages);

        return $results;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    protected function send(string $method, string $path, array $data = []): array
    {
        try {
            $response = $this->request()->{$method}($path, $data);
        } catch (ConnectionException $exception) {
            throw new CloudflareException('Could not reach Cloudflare: '.$exception->getMessage());
        }

        if ($response->failed() || $response->json('success') === false) {
            throw CloudflareException::fromResponse($response);
        }

        return $response->json() ?? [];
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->retry(2, 500, fn ($exception) => $exception instanceof ConnectionException, throw: false);
    }
}
