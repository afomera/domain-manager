<?php

namespace App\Enums;

/**
 * A company that can act as a domain's registrar and/or DNS host.
 */
enum Provider: string
{
    case Cloudflare = 'cloudflare';
    case Squarespace = 'squarespace';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cloudflare => 'Cloudflare',
            self::Squarespace => 'Squarespace',
            self::Other => 'Other',
        };
    }

    /**
     * Map a registrar's legal name (as reported by RDAP) onto a known provider.
     * Google Domains registrations moved to Squarespace, so they count as Squarespace.
     */
    public static function fromRegistrarName(?string $registrarName): self
    {
        $registrarName = strtolower((string) $registrarName);

        return match (true) {
            str_contains($registrarName, 'cloudflare') => self::Cloudflare,
            str_contains($registrarName, 'squarespace'), str_contains($registrarName, 'google') => self::Squarespace,
            default => self::Other,
        };
    }
}
