<?php

namespace App\Jobs;

use App\Events\PortfolioUpdated;
use App\Models\CloudflareConnection;
use App\Services\Cloudflare\CloudflareException;
use App\Services\Cloudflare\CloudflareSync;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Starts a Cloudflare sync in the background. Does the quick part itself (zones, Registrar data,
 * prices), then fans the slow part out as a batch of small jobs — one per zone and one per RDAP
 * lookup — so every job stays well inside managed queues' time limit, and zones import in parallel.
 */
class SyncCloudflareConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * A sync either works or reports its error on the connection; retrying blindly won't help.
     */
    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(public CloudflareConnection $cloudflareConnection) {}

    public function uniqueId(): string
    {
        return (string) $this->cloudflareConnection->id;
    }

    public function handle(CloudflareSync $sync): void
    {
        $connection = $this->cloudflareConnection;

        $connection->update([
            'sync_status' => 'running',
            'sync_progress' => ['message' => 'Fetching zones and Registrar data', 'percent' => 5],
            'sync_started_at' => now(),
            'sync_batch_id' => null,
        ]);
        PortfolioUpdated::dispatch($connection->user_id);

        try {
            $plan = $sync->prepare($connection);
        } catch (CloudflareException) {
            // Already recorded on the connection as last_sync_error for the UI.
            self::stopTracking($connection);

            return;
        }

        $jobs = [
            ...array_map(fn (int $domainId) => new ImportZoneRecords($domainId), array_keys($plan['zones'])),
            ...array_map(fn (int $domainId) => new LookUpDomainRegistrar($domainId, $plan['synced_at']), $plan['lookups']),
        ];

        if ($jobs === []) {
            self::complete($connection->id, $plan['synced_at'], $plan['warnings'], failures: 0);

            return;
        }

        $connectionId = $connection->id;
        $syncedAt = $plan['synced_at'];
        $warnings = $plan['warnings'];

        $batch = Bus::batch($jobs)
            ->name("Cloudflare sync for connection {$connectionId}")
            ->allowFailures()
            ->finally(static fn (Batch $batch) => self::complete($connectionId, $syncedAt, $warnings, $batch->failedJobs))
            ->dispatch();

        // The batch may already be done (sync queue in tests); only record it if it's still running.
        if ($connection->fresh()?->sync_status === 'running') {
            $connection->update(['sync_batch_id' => $batch->id]);
        }
    }

    /**
     * Runs once the whole batch has finished, failed jobs included.
     *
     * @param  list<string>  $warnings
     */
    public static function complete(int $connectionId, string $syncedAt, array $warnings, int $failures): void
    {
        $connection = CloudflareConnection::find($connectionId);

        if (! $connection) {
            return;
        }

        if ($failures > 0) {
            $warnings[] = $failures === 1
                ? 'One zone or lookup couldn’t be synced; the next sync will retry it.'
                : "{$failures} zones or lookups couldn’t be synced; the next sync will retry them.";
        }

        app(CloudflareSync::class)->finish($connection, CarbonImmutable::parse($syncedAt), $warnings);
        self::stopTracking($connection);
    }

    public function failed(?Throwable $exception): void
    {
        $this->cloudflareConnection->update([
            'last_sync_error' => 'The sync stopped unexpectedly'.($exception ? ': '.$exception->getMessage() : '.'),
        ]);

        self::stopTracking($this->cloudflareConnection);
    }

    protected static function stopTracking(CloudflareConnection $connection): void
    {
        $connection->update(['sync_status' => null, 'sync_progress' => null, 'sync_batch_id' => null]);
        PortfolioUpdated::dispatch($connection->user_id);
    }
}
