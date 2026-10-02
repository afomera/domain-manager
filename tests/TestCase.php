<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Cloudflare and RDAP must always be faked in tests.
        Http::preventStrayRequests();
    }

    /**
     * A successful Cloudflare v4 response envelope.
     *
     * @param  array<string, mixed>  $resultInfo
     * @return array<string, mixed>
     */
    protected function cloudflareResponse(mixed $result, array $resultInfo = []): array
    {
        return ['success' => true, 'errors' => [], 'messages' => [], 'result' => $result, 'result_info' => $resultInfo ?: ['page' => 1, 'total_pages' => 1]];
    }

    /**
     * A failed Cloudflare v4 response envelope.
     *
     * @return array<string, mixed>
     */
    protected function cloudflareError(string $message, int $code = 1000): array
    {
        return ['success' => false, 'errors' => [['code' => $code, 'message' => $message]], 'messages' => [], 'result' => null];
    }
}
