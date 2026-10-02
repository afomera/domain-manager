<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\Cloudflare\CloudflareSync;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One zone's DNS records, as part of a Cloudflare sync batch. Small on purpose: managed queues on
 * Flex compute stop jobs after about a minute.
 */
class ImportZoneRecords implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(public int $domainId) {}

    public function handle(CloudflareSync $sync): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $domain = Domain::query()->with('user.cloudflareConnection')->find($this->domainId);

        if (! $domain) {
            return;
        }

        $domain->user->cloudflareConnection?->update(['sync_progress' => ['message' => "Importing DNS records for {$domain->name}"]]);

        $sync->importRecords($domain);
    }
}
