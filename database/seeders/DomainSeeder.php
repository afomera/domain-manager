<?php

namespace Database\Seeders;

use App\Enums\Provider;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Sample portfolio from the mockup, owned by demo@example.com (password: "password").
 * These domains aren't linked to Cloudflare, so DNS edits on them are only saved locally.
 */
class DomainSeeder extends Seeder
{
    /**
     * @var list<array{0: string, 1: Provider, 2: Provider, 3: string, 4: string, 5: bool, 6: int, 7?: string}>
     */
    private const array DOMAINS = [
        ['harborlight.dev', Provider::Cloudflare, Provider::Cloudflare, '2021-03-14', '2027-03-14', true, 1220],
        ['quietmetrics.com', Provider::Cloudflare, Provider::Cloudflare, '2019-10-19', '2026-10-19', true, 1046],
        ['railsnotebook.io', Provider::Cloudflare, Provider::Cloudflare, '2022-08-02', '2027-08-02', true, 5000],
        ['tinyinvoice.app', Provider::Cloudflare, Provider::Cloudflare, '2023-12-05', '2026-12-05', true, 1420],
        ['brightledger.com', Provider::Cloudflare, Provider::Cloudflare, '2018-05-30', '2027-05-30', true, 1046, 'Transfer pending'],
        ['deploywindow.dev', Provider::Cloudflare, Provider::Cloudflare, '2024-04-01', '2027-04-01', true, 1220],
        ['pagewright.co', Provider::Squarespace, Provider::Squarespace, '2020-10-09', '2026-10-09', false, 3000],
        ['oldbandsite.net', Provider::Squarespace, Provider::Squarespace, '2015-10-04', '2026-10-04', false, 2000],
        ['maplecourt.org', Provider::Squarespace, Provider::Cloudflare, '2017-01-22', '2027-01-22', true, 2000],
        ['hanamiweekly.com', Provider::Squarespace, Provider::Cloudflare, '2024-11-15', '2026-11-15', true, 2000],
        ['fieldnotes.studio', Provider::Squarespace, Provider::Squarespace, '2022-06-30', '2027-06-30', true, 3000],
        ['launchqueue.com', Provider::Squarespace, Provider::Squarespace, '2026-08-20', '2027-08-20', true, 2000],
        ['gardenledger.com', Provider::Squarespace, Provider::Cloudflare, '2016-02-11', '2027-02-11', true, 2000],
    ];

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo', 'password' => 'password'],
        );

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        foreach (self::DOMAINS as $row) {
            [$name, $registrar, $nameservers, $registeredOn, $expiresOn, $autoRenew, $priceCents] = $row;

            $domain = $user->domains()->updateOrCreate(['name' => $name], [
                'registrar' => $registrar,
                'nameserver_provider' => $nameservers,
                'registered_on' => $registeredOn,
                'expires_on' => $expiresOn,
                'auto_renew' => $autoRenew,
                'renewal_price_cents' => $priceCents,
                'note' => $row[7] ?? null,
                'synced_at' => now()->subMinutes(4),
            ]);

            if ($domain->hasCloudflareDns() && $domain->dnsRecords()->doesntExist()) {
                $domain->dnsRecords()->createMany($this->recordsFor($name));
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recordsFor(string $name): array
    {
        $records = [
            ['type' => 'A', 'name' => '@', 'content' => '76.76.21.21', 'proxied' => true],
            ['type' => 'CNAME', 'name' => 'www', 'content' => $name, 'proxied' => true],
            ['type' => 'MX', 'name' => '@', 'content' => 'in1-smtp.messagingengine.com', 'priority' => 10, 'ttl' => 3600],
            ['type' => 'MX', 'name' => '@', 'content' => 'in2-smtp.messagingengine.com', 'priority' => 20, 'ttl' => 3600],
            ['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 include:spf.messagingengine.com ?all', 'ttl' => 3600],
            ['type' => 'TXT', 'name' => '_dmarc', 'content' => "v=DMARC1; p=quarantine; rua=mailto:dmarc@{$name}", 'ttl' => 3600],
            ['type' => 'CNAME', 'name' => 'fm1._domainkey', 'content' => "fm1.{$name}.dkim.fmhosted.com"],
        ];

        if ($name === 'tinyinvoice.app') {
            $records[] = ['type' => 'CNAME', 'name' => 'app', 'content' => 'cname.vercel-dns.com'];
        }

        if ($name === 'railsnotebook.io') {
            $records[] = ['type' => 'AAAA', 'name' => '@', 'content' => '2606:4700:3030::6815:1e8', 'proxied' => true];
        }

        return $records;
    }
}
