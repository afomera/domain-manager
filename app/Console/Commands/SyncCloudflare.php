<?php

namespace App\Console\Commands;

use App\Models\CloudflareConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cloudflare:sync')]
#[Description('Queue a Cloudflare sync for every connected user')]
class SyncCloudflare extends Command
{
    /**
     * Queues rather than syncing inline, so the work runs as small queued jobs (and shows progress in the UI).
     */
    public function handle(): int
    {
        CloudflareConnection::query()->with('user')->each(function (CloudflareConnection $connection) {
            if ($connection->isSyncing()) {
                $this->components->warn("{$connection->user->email}: a sync is already running, skipped");

                return;
            }

            $connection->queueSync();
            $this->components->info("{$connection->user->email}: sync queued");
        });

        return self::SUCCESS;
    }
}
