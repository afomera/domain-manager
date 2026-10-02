<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\Cloudflare\CloudflareSync;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * RDAP lookup for one domain registered outside Cloudflare, as part of a Cloudflare sync batch.
 */
class LookUpDomainRegistrar implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $domainId, public string $syncedAt) {}

    public function handle(CloudflareSync $sync): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $domain = Domain::query()->with('user.cloudflareConnection')->find($this->domainId);

        if (! $domain) {
            return;
        }

        $domain->user->cloudflareConnection?->update(['sync_progress' => ['message' => "Looking up the registrar for {$domain->name}"]]);

        $sync->lookUpRegistrar($domain, CarbonImmutable::parse($this->syncedAt));
    }
}
