<?php

namespace App\Services\Cloudflare;

use Exception;
use Illuminate\Http\Client\Response;

class CloudflareException extends Exception
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $errors = collect($response->json('errors') ?? [])
            ->map(fn (array $error) => trim(($error['message'] ?? '').(isset($error['code']) ? " ({$error['code']})" : '')))
            ->filter()
            ->implode('; ');

        return new self($errors ?: "Cloudflare responded with HTTP {$response->status()}.", $response->status());
    }

    /**
     * The token works, but lacks the permission this request needs.
     */
    public function isPermissionError(): bool
    {
        return $this->status === 403;
    }
}
