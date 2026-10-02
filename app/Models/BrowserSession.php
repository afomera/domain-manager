<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signed-in browser, read from Laravel's database session store (SESSION_DRIVER=database).
 *
 * @property string $id
 * @property ?int $user_id
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property int $last_activity
 */
class BrowserSession extends Model
{
    protected $table = 'sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastActiveAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($this->last_activity);
    }

    /**
     * "Safari on macOS", "Chrome on Android", or "Unknown browser".
     */
    public function description(): string
    {
        $browser = $this->browser();
        $platform = $this->platform();

        return $platform ? "{$browser} on {$platform}" : $browser;
    }

    public function isMobile(): bool
    {
        return (bool) preg_match('/Mobile|iPhone|Android(?!.*Tablet)/i', (string) $this->user_agent)
            && ! str_contains((string) $this->user_agent, 'iPad');
    }

    /**
     * Order matters: Edge and Opera also claim to be Chrome, and Chrome claims to be Safari.
     */
    protected function browser(): string
    {
        $agent = (string) $this->user_agent;

        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/'), str_contains($agent, 'FxiOS/') => 'Firefox',
            str_contains($agent, 'Chrome/'), str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') && str_contains($agent, 'Version/') => 'Safari',
            default => 'Unknown browser',
        };
    }

    protected function platform(): ?string
    {
        $agent = (string) $this->user_agent;

        return match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };
    }
}
