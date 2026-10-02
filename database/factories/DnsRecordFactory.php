<?php

namespace Database\Factories;

use App\Enums\DnsRecordType;
use App\Models\DnsRecord;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsRecord>
 */
class DnsRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'type' => DnsRecordType::A,
            'name' => '@',
            'content' => fake()->ipv4(),
            'ttl' => 1,
            'proxied' => true,
            'priority' => null,
        ];
    }

    public function cname(string $name, string $target): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DnsRecordType::CNAME,
            'name' => $name,
            'content' => $target,
        ]);
    }

    public function mx(string $server, int $priority = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DnsRecordType::MX,
            'content' => $server,
            'priority' => $priority,
            'ttl' => 3600,
            'proxied' => false,
        ]);
    }

    public function txt(string $name, string $content): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DnsRecordType::TXT,
            'name' => $name,
            'content' => $content,
            'ttl' => 3600,
            'proxied' => false,
        ]);
    }
}
