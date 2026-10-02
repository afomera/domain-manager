<?php

namespace Database\Factories;

use App\Models\CloudflareConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CloudflareConnection>
 */
class CloudflareConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(40);

        return [
            'user_id' => User::factory(),
            'api_token' => $token,
            'token_hint' => CloudflareConnection::hintFor($token),
            'verified_at' => now(),
        ];
    }
}
