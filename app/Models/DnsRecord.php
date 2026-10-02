<?php

namespace App\Models;

use App\Enums\DnsRecordType;
use Database\Factories\DnsRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $domain_id
 * @property ?string $cloudflare_id
 * @property DnsRecordType $type
 * @property string $name
 * @property string $content
 * @property int $ttl
 * @property bool $proxied
 * @property ?int $priority
 */
#[Fillable(['cloudflare_id', 'type', 'name', 'content', 'ttl', 'proxied', 'priority'])]
class DnsRecord extends Model
{
    /** @use HasFactory<DnsRecordFactory> */
    use HasFactory;

    /**
     * TTL choices in seconds. Cloudflare treats 1 as "automatic".
     */
    public const array TTL_OPTIONS = [
        1 => 'Auto',
        60 => '1 min',
        300 => '5 min',
        3600 => '1 hr',
        86400 => '1 day',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DnsRecordType::class,
            'ttl' => 'integer',
            'proxied' => 'boolean',
            'priority' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function ttlLabel(): string
    {
        return self::ttlLabelFor($this->ttl);
    }

    public static function ttlLabelFor(int $ttl): string
    {
        return self::TTL_OPTIONS[$ttl] ?? $ttl.'s';
    }

    /**
     * Cloudflare uses fully qualified names; records here are stored relative to the zone, with "@" for the apex.
     */
    public static function relativeName(string $fqdn, string $zoneName): string
    {
        $fqdn = rtrim(strtolower($fqdn), '.');
        $zoneName = strtolower($zoneName);

        if ($fqdn === $zoneName) {
            return '@';
        }

        return str_ends_with($fqdn, '.'.$zoneName) ? substr($fqdn, 0, -strlen($zoneName) - 1) : $fqdn;
    }

    public static function fullyQualifiedName(string $relativeName, string $zoneName): string
    {
        return $relativeName === '@' ? $zoneName : "{$relativeName}.{$zoneName}";
    }
}
