<?php

namespace App\Models;

use App\Enums\Provider;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\CloudflareException;
use Carbon\CarbonImmutable;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $user_id
 * @property string $name
 * @property Provider $registrar
 * @property ?string $registrar_name
 * @property ?string $registration_status
 * @property Provider $nameserver_provider
 * @property ?list<string> $nameservers
 * @property ?CarbonImmutable $registered_on
 * @property ?CarbonImmutable $expires_on
 * @property bool $auto_renew
 * @property ?int $renewal_price_cents
 * @property ?string $renewal_price_source "cloudflare" when synced from Cloudflare's at-cost pricing, "manual" when set by hand
 * @property ?string $note
 * @property ?string $cloudflare_account_id
 * @property ?string $cloudflare_zone_id
 * @property ?string $cloudflare_zone_status
 * @property ?CarbonImmutable $synced_at
 */
#[Fillable([
    'name',
    'registrar',
    'registrar_name',
    'registration_status',
    'nameserver_provider',
    'nameservers',
    'registered_on',
    'expires_on',
    'auto_renew',
    'renewal_price_cents',
    'renewal_price_source',
    'note',
    'cloudflare_account_id',
    'cloudflare_zone_id',
    'cloudflare_zone_status',
    'synced_at',
])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    /**
     * Steps to move a domain from Squarespace to Cloudflare, in order.
     */
    public const array TRANSFER_STEPS = [
        'Add zone to Cloudflare',
        'Point nameservers to Cloudflare',
        'Unlock at Squarespace',
        'Copy auth code',
        'Start transfer in Cloudflare',
        'Approve transfer email',
    ];

    /**
     * Days without auto-renew before expiry that a domain is flagged.
     */
    public const int EXPIRY_WARNING_DAYS = 30;

    /**
     * ICANN blocks registrar transfers for this many days after registration.
     */
    public const int TRANSFER_LOCK_DAYS = 60;

    public const string PRICE_FROM_CLOUDFLARE = 'cloudflare';

    public const string PRICE_SET_MANUALLY = 'manual';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registrar' => Provider::class,
            'nameserver_provider' => Provider::class,
            'nameservers' => 'array',
            'registered_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'auto_renew' => 'boolean',
            'renewal_price_cents' => 'integer',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * URLs use the domain name. Resolution is scoped to the signed-in user in AppServiceProvider.
     */
    public function getRouteKeyName(): string
    {
        return 'name';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<DnsRecord, $this>
     */
    public function dnsRecords(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }

    public function registrarLabel(): string
    {
        return $this->registrar === Provider::Other && $this->registrar_name
            ? $this->registrar_name
            : $this->registrar->label();
    }

    public function isRegisteredWithCloudflare(): bool
    {
        return $this->registrar === Provider::Cloudflare && $this->cloudflare_account_id !== null;
    }

    /**
     * A Cloudflare zone exists, so DNS changes here go through the Cloudflare API.
     */
    public function hasCloudflareZone(): bool
    {
        return $this->cloudflare_zone_id !== null;
    }

    /**
     * @throws CloudflareException when the owner hasn't connected Cloudflare.
     */
    public function cloudflareClient(): CloudflareClient
    {
        $connection = $this->user->cloudflareConnection
            ?? throw new CloudflareException('Connect Cloudflare in Settings to make changes to this domain.');

        return $connection->client();
    }

    public function daysUntilExpiry(): ?int
    {
        return $this->expires_on ? (int) CarbonImmutable::today()->diffInDays($this->expires_on, false) : null;
    }

    /**
     * Expiring soon with nothing in place to renew it — the one state that warrants color.
     */
    public function isExpiringWithoutRenewal(): bool
    {
        return ! $this->auto_renew
            && $this->expires_on !== null
            && $this->daysUntilExpiry() <= self::EXPIRY_WARNING_DAYS;
    }

    public function transferLockedUntil(): ?CarbonImmutable
    {
        $unlocksOn = $this->registered_on?->addDays(self::TRANSFER_LOCK_DAYS);

        return $unlocksOn?->isFuture() ? $unlocksOn : null;
    }

    public function hasCloudflareDns(): bool
    {
        return $this->nameserver_provider === Provider::Cloudflare;
    }

    public function hasSplitProviders(): bool
    {
        return $this->registrar !== $this->nameserver_provider;
    }

    public function isMigratingToCloudflare(): bool
    {
        return $this->registrar === Provider::Squarespace;
    }

    public function completedTransferSteps(): int
    {
        return match (true) {
            $this->hasCloudflareDns() => 2,
            $this->hasCloudflareZone() => 1,
            default => 0,
        };
    }

    public function formattedRenewalPrice(): string
    {
        return $this->renewal_price_cents === null ? '—' : self::formatCents($this->renewal_price_cents);
    }

    /**
     * The single quiet status shown at the end of a domain's row, if any.
     * Expiry warnings are rendered separately so they can be colored.
     */
    public function statusNote(): ?string
    {
        if ($this->note) {
            return $this->note;
        }

        if ($this->registration_status === 'transfer_pending') {
            return 'Transfer pending';
        }

        if ($this->cloudflare_zone_status === 'pending') {
            return 'Waiting for nameservers';
        }

        if ($lockedUntil = $this->transferLockedUntil()) {
            return 'Transfer locked until '.$lockedUntil->format('M j');
        }

        if ($this->registrar === Provider::Squarespace && $this->hasCloudflareDns()) {
            return 'Ready to transfer';
        }

        return null;
    }

    public static function formatCents(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}
