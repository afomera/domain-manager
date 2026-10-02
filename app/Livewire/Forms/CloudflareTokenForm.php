<?php

namespace App\Livewire\Forms;

use App\Models\CloudflareConnection;
use App\Models\User;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\CloudflareException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

/**
 * Connecting (or replacing) a Cloudflare API token. Shared by onboarding and Settings.
 */
class CloudflareTokenForm extends Form
{
    /**
     * Write-only: cleared as soon as the token is stored, so it never round-trips to the browser.
     */
    public string $token = '';

    /**
     * Optional "YYYY-MM-DD" so we can remind the user to rotate it. Filled from Cloudflare when the token has one.
     */
    public string $expiresOn = '';

    /**
     * Validate, verify the token with Cloudflare, then store it encrypted.
     *
     * @throws ValidationException when the input is invalid or Cloudflare rejects the token
     */
    public function connect(User $user): CloudflareConnection
    {
        $this->validate([
            'token' => ['required', 'string', 'min:20', 'max:255'],
            'expiresOn' => ['nullable', 'date', 'after_or_equal:today'],
        ], [
            'token.*' => 'Paste a Cloudflare API token.',
            'expiresOn.*' => 'Pick a date from today onward, or leave it blank.',
        ]);

        $token = trim($this->token);

        try {
            $details = (new CloudflareClient($token))->verifyToken();
        } catch (CloudflareException $exception) {
            throw ValidationException::withMessages([
                $this->getPropertyName().'.token' => 'Cloudflare rejected this token: '.$exception->getMessage(),
            ]);
        }

        $connection = $user->cloudflareConnection()->updateOrCreate([], [
            'api_token' => $token,
            'token_hint' => CloudflareConnection::hintFor($token),
            'token_expires_on' => $this->expiresOn ?: (isset($details['expires_on']) ? Carbon::parse($details['expires_on']) : null),
            'verified_at' => now(),
            'last_sync_error' => null,
        ]);

        // Connecting Cloudflare is the end of onboarding, wherever it happens.
        $user->markOnboarded();

        $this->reset();

        return $connection;
    }
}
