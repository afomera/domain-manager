<?php

namespace App\Services\Rdap;

use Carbon\CarbonImmutable;

/**
 * Public registration facts for a domain, as reported by its registry.
 */
final readonly class RdapRegistration
{
    public function __construct(
        public ?string $registrarName,
        public ?CarbonImmutable $registeredOn,
        public ?CarbonImmutable $expiresOn,
    ) {}
}
