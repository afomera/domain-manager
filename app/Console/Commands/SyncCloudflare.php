<?php

namespace App\Console\Commands;

use App\Models\CloudflareConnection;
use App\Services\Cloudflare\CloudflareException;
use App\Services\Cloudflare\CloudflareSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cloudflare:sync')]
#[Description('Pull domains, zones and DNS records from Cloudflare for every connected user')]
class SyncCloudflare extends Command
{
    public function handle(CloudflareSync $sync): int
    {
        $failures = 0;

        CloudflareConnection::query()->with('user')->each(function (CloudflareConnection $connection) use ($sync, &$failures) {
            if ($connection->isSyncing()) {
                $this->components->warn("{$connection->user->email}: a sync is already running, skipped");

                return;
            }

            try {
                $result = $sync->runTracked($connection);
                $this->components->info("{$connection->user->email}: {$result['domains']} domains, {$result['records']} records");
            } catch (CloudflareException $exception) {
                $failures++;
                $this->components->error("{$connection->user->email}: {$exception->getMessage()}");
            }
        });

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
