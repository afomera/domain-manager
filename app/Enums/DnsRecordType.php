<?php

namespace App\Enums;

enum DnsRecordType: string
{
    case A = 'A';
    case AAAA = 'AAAA';
    case CNAME = 'CNAME';
    case MX = 'MX';
    case TXT = 'TXT';
    case NS = 'NS';
    case CAA = 'CAA';
    case SRV = 'SRV';
    case HTTPS = 'HTTPS';
    case SVCB = 'SVCB';
    case PTR = 'PTR';
    case DS = 'DS';
    case TLSA = 'TLSA';
    case SSHFP = 'SSHFP';
    case CERT = 'CERT';
    case DNSKEY = 'DNSKEY';
    case NAPTR = 'NAPTR';
    case SMIMEA = 'SMIMEA';
    case URI = 'URI';
    case LOC = 'LOC';
    case OPENPGPKEY = 'OPENPGPKEY';

    /**
     * Types that can be created and edited here. Everything else is synced read-only.
     *
     * @return list<self>
     */
    public static function editable(): array
    {
        return [self::A, self::AAAA, self::CNAME, self::MX, self::TXT];
    }

    public function isEditable(): bool
    {
        return in_array($this, self::editable(), true);
    }

    /**
     * Only address and alias records can be routed through Cloudflare's proxy.
     */
    public function isProxyable(): bool
    {
        return in_array($this, [self::A, self::AAAA, self::CNAME], true);
    }

    public function contentLabel(): string
    {
        return match ($this) {
            self::A => 'IPv4 address',
            self::AAAA => 'IPv6 address',
            self::CNAME => 'Target',
            self::MX => 'Mail server',
            default => 'Content',
        };
    }

    /**
     * Position used when listing records, so a zone reads top-down: addresses, aliases, mail, text, the rest.
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::A => 0,
            self::AAAA => 1,
            self::CNAME => 2,
            self::MX => 3,
            self::TXT => 4,
            default => 5,
        };
    }
}
