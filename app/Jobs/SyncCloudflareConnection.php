<?php

namespace App\Jobs;

use App\Models\CloudflareConnection;
use App\Services\Cloudflare\CloudflareException;
use App\Services\Cloudflare\CloudflareSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a Cloudflare sync in the background so the UI can show progress instead of blocking a request.
 */
class SyncCloudflareConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * A sync either works or reports its error on the connection; retrying blindly won't help.
     */
    public int $tries = 1;

    /**
     * First imports of large accounts make many API calls.
     */
    public int $timeout = 600;

    public function __construct(public CloudflareConnection $cloudflareConnection) {}

    public function uniqueId(): string
    {
        return (string) $this->cloudflareConnection->id;
    }

    public function handle(CloudflareSync $sync): void
    {
        try {
            $sync->runTracked($this->cloudflareConnection);
        } catch (CloudflareException) {
            // Already recorded on the connection as last_sync_error for the UI.
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->cloudflareConnection->update([
            'sync_status' => null,
            'sync_progress' => null,
            'last_sync_error' => 'The sync stopped unexpectedly'.($exception ? ': '.$exception->getMessage() : '.'),
        ]);
    }
}
