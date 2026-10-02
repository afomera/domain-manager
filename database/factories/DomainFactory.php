<?php

namespace Database\Factories;

use App\Enums\Provider;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $registeredOn = fake()->dateTimeBetween('-8 years', '-1 year');

        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->domainWord().'.'.fake()->randomElement(['com', 'dev', 'io', 'app', 'org']),
            'registrar' => Provider::Cloudflare,
            'nameserver_provider' => Provider::Cloudflare,
            'registered_on' => $registeredOn,
            'expires_on' => now()->addDays(fake()->numberBetween(60, 360)),
            'auto_renew' => true,
            'renewal_price_cents' => fake()->randomElement([1046, 1220, 1420, 5000]),
            'synced_at' => now(),
        ];
    }

    /**
     * Registered with Cloudflare Registrar and hosted in a Cloudflare zone, as the sync creates it.
     */
    public function syncedFromCloudflare(): static
    {
        return $this->state(fn (array $attributes) => [
            'cloudflare_account_id' => 'acc_'.fake()->bothify('????????'),
            'cloudflare_zone_id' => 'zone_'.fake()->unique()->bothify('????????'),
            'cloudflare_zone_status' => 'active',
            'nameservers' => ['ada.ns.cloudflare.com', 'kurt.ns.cloudflare.com'],
        ]);
    }

    public function onSquarespace(): static
    {
        return $this->state(fn (array $attributes) => [
            'registrar' => Provider::Squarespace,
            'nameserver_provider' => Provider::Squarespace,
            'renewal_price_cents' => 2000,
        ]);
    }

    /**
     * Registered at Squarespace, but DNS already moved to Cloudflare.
     */
    public function readyToTransfer(): static
    {
        return $this->onSquarespace()->state(fn (array $attributes) => [
            'nameserver_provider' => Provider::Cloudflare,
        ]);
    }

    public function expiringWithoutRenewal(int $days = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_on' => now()->addDays($days),
            'auto_renew' => false,
        ]);
    }
}
