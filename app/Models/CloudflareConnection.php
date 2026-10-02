<?php

namespace App\Models;

use App\Events\PortfolioUpdated;
use App\Jobs\SyncCloudflareConnection;
use App\Services\Cloudflare\CloudflareClient;
use Carbon\CarbonImmutable;
use Database\Factories\CloudflareConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Bus;

/**
 * A user's Cloudflare API token. The token is encrypted at rest with the app key.
 *
 * @property int $user_id
 * @property string $api_token
 * @property string $token_hint
 * @property ?CarbonImmutable $token_expires_on
 * @property ?CarbonImmutable $verified_at
 * @property ?CarbonImmutable $last_synced_at
 * @property ?string $last_sync_error
 * @property ?string $sync_status "queued" or "running" while a sync is in flight
 * @property ?array{message: string, percent?: int} $sync_progress
 * @property ?CarbonImmutable $sync_started_at
 * @property ?string $sync_batch_id the job batch importing zones and lookups, once the quick phase is done
 */
#[Fillable(['api_token', 'token_hint', 'token_expires_on', 'verified_at', 'last_synced_at', 'last_sync_error', 'sync_status', 'sync_progress', 'sync_started_at', 'sync_batch_id'])]
#[Hidden(['api_token'])]
class CloudflareConnection extends Model
{
    /** @use HasFactory<CloudflareConnectionFactory> */
    use HasFactory;

    /**
     * Start reminding the user to rotate the token this many days before it expires.
     */
    public const int ROTATION_REMINDER_DAYS = 14;

    /**
     * A sync still marked in flight after this long is assumed to have died (worker killed, deploy, etc).
     */
    public const int SYNC_STALE_AFTER_MINUTES = 15;

    /**
     * A queued sync that hasn't started after this long probably has no queue worker to run it.
     */
    public const int SYNC_WAITING_WARNING_SECONDS = 20;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'token_expires_on' => 'immutable_date',
            'verified_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'sync_progress' => 'array',
            'sync_started_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function daysUntilTokenExpires(): ?int
    {
        return $this->token_expires_on ? (int) CarbonImmutable::today()->diffInDays($this->token_expires_on, false) : null;
    }

    public function tokenHasExpired(): bool
    {
        return $this->token_expires_on !== null && $this->daysUntilTokenExpires() < 0;
    }

    public function tokenNeedsRotation(): bool
    {
        return $this->token_expires_on !== null && $this->daysUntilTokenExpires() <= self::ROTATION_REMINDER_DAYS;
    }

    /**
     * A one-line reminder for an expiring token, or null when no reminder is due.
     */
    public function rotationReminder(): ?string
    {
        if (! $this->tokenNeedsRotation()) {
            return null;
        }

        $days = $this->daysUntilTokenExpires();

        return match (true) {
            $days < 0 => 'Your Cloudflare API token expired on '.$this->token_expires_on->format('M j').'. Syncing and DNS changes will fail until you replace it.',
            $days === 0 => 'Your Cloudflare API token expires today.',
            default => "Your Cloudflare API token expires in {$days} ".str('day')->plural($days).' ('.$this->token_expires_on->format('M j').').',
        };
    }

    /**
     * Mark a sync as queued and dispatch it. Does nothing if one is already in flight.
     */
    public function queueSync(): void
    {
        if ($this->isSyncing()) {
            return;
        }

        $this->update([
            'sync_status' => 'queued',
            'sync_progress' => ['message' => 'Waiting to start…', 'percent' => 0],
            'sync_started_at' => now(),
        ]);

        SyncCloudflareConnection::dispatch($this);
        PortfolioUpdated::dispatch($this->user_id);
    }

    public function isSyncing(): bool
    {
        return $this->sync_status !== null
            && $this->sync_started_at?->gt(now()->subMinutes(self::SYNC_STALE_AFTER_MINUTES));
    }

    /**
     * Queued for a while without starting: most likely no queue worker is running.
     */
    public function isWaitingForWorker(): bool
    {
        return $this->sync_status === 'queued'
            && $this->sync_started_at?->lt(now()->subSeconds(self::SYNC_WAITING_WARNING_SECONDS));
    }

    /**
     * Overall progress: the quick phase reports its own percent; after that, the batch's progress fills 20–100%.
     */
    public function syncPercent(): int
    {
        if ($this->sync_batch_id && $batch = Bus::findBatch($this->sync_batch_id)) {
            return (int) floor(20 + 0.8 * $batch->progress());
        }

        return (int) ($this->sync_progress['percent'] ?? 0);
    }

    public function syncMessage(): string
    {
        return $this->sync_progress['message'] ?? 'Starting…';
    }

    public function client(): CloudflareClient
    {
        return new CloudflareClient($this->api_token);
    }

    /**
     * The last few characters of a token, safe to show so the user can tell which token is saved.
     */
    public static function hintFor(string $token): string
    {
        return substr($token, -4);
    }
}
