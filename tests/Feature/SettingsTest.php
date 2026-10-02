<?php

namespace Tests\Feature;

use App\Jobs\SyncCloudflareConnection;
use App\Models\CloudflareConnection;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN = 'cf-live-token-abcdefghijklmnopqrstuvwxyz';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['password' => 'old-password']);
    }

    public function test_settings_requires_sign_in(): void
    {
        $this->get(route('settings'))->assertRedirect(route('login'));
    }

    public function test_connecting_verifies_encrypts_and_syncs(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response($this->cloudflareResponse(['id' => 'tok', 'status' => 'active'])),
            'api.cloudflare.com/client/v4/accounts?*' => Http::response($this->cloudflareResponse([])),
            'api.cloudflare.com/client/v4/zones?*' => Http::response($this->cloudflareResponse([])),
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::settings')
            ->set('tokenForm.token', self::TOKEN)
            ->call('connectCloudflare')
            ->assertHasNoErrors()
            ->assertSet('tokenForm.token', '')
            ->assertRedirect(route('domains.index'));

        $connection = $this->user->cloudflareConnection;
        $this->assertSame(self::TOKEN, $connection->api_token);
        $this->assertSame('wxyz', $connection->token_hint);
        $this->assertNotSame(self::TOKEN, DB::table('cloudflare_connections')->value('api_token'));
        $this->assertNotNull($connection->last_synced_at, 'The queued sync ran (the test queue is synchronous).');
        $this->assertNull($connection->sync_status);
    }

    public function test_replacing_a_token_stays_on_settings(): void
    {
        CloudflareConnection::factory()->for($this->user)->create(['last_synced_at' => now()->subDay()]);
        Queue::fake();
        Http::fake(['api.cloudflare.com/client/v4/user/tokens/verify' => Http::response($this->cloudflareResponse(['status' => 'active']))]);

        Livewire::actingAs($this->user)
            ->test('pages::settings')
            ->set('replacingToken', true)
            ->set('tokenForm.token', self::TOKEN)
            ->call('connectCloudflare')
            ->assertNoRedirect()
            ->assertSee('••••••••wxyz')
            ->assertSee('Syncing with Cloudflare')
            ->assertDontSee(self::TOKEN);

        Queue::assertPushed(SyncCloudflareConnection::class);
    }

    public function test_a_rejected_token_is_not_saved(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response($this->cloudflareError('Invalid API Token'), 401),
            'api.cloudflare.com/client/v4/accounts?*' => Http::response($this->cloudflareError('Invalid API Token'), 401),
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::settings')
            ->set('tokenForm.token', self::TOKEN)
            ->call('connectCloudflare')
            ->assertHasErrors('tokenForm.token');

        $this->assertNull($this->user->cloudflareConnection);
    }

    public function test_disconnecting_removes_the_token(): void
    {
        CloudflareConnection::factory()->for($this->user)->create();

        Livewire::actingAs($this->user)
            ->test('pages::settings')
            ->call('disconnect');

        $this->assertNull($this->user->fresh()->cloudflareConnection);
    }

    public function test_profile_and_password_can_be_updated(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::settings')
            ->set('name', 'Andrea')
            ->set('email', 'andrea@example.com')
            ->call('saveProfile')
            ->assertHasNoErrors()
            ->set('current_password', 'old-password')
            ->set('password', 'a-much-better-password')
            ->set('password_confirmation', 'a-much-better-password')
            ->call('savePassword')
            ->assertHasNoErrors();

        $this->user->refresh();
        $this->assertSame('andrea@example.com', $this->user->email);
        $this->assertTrue(Hash::check('a-much-better-password', $this->user->password));
    }

    public function test_password_change_requires_the_current_password(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::settings')
            ->set('current_password', 'wrong')
            ->set('password', 'a-much-better-password')
            ->set('password_confirmation', 'a-much-better-password')
            ->call('savePassword')
            ->assertHasErrors('current_password');
    }

    public function test_token_expiry_is_taken_from_cloudflare_when_left_blank(): void
    {
        $this->fakeSuccessfulConnect(expiresOn: now()->addDays(90)->toIso8601String());

        Livewire::actingAs($this->user)
            ->test('pages::settings')
            ->set('tokenForm.token', self::TOKEN)
            ->call('connectCloudflare')
            ->assertHasNoErrors();

        $this->assertSame(now()->addDays(90)->toDateString(), $this->user->cloudflareConnection->token_expires_on->toDateString());
    }

    public function test_a_chosen_expiry_overrides_cloudflares(): void
    {
        $this->fakeSuccessfulConnect(expiresOn: now()->addDays(90)->toIso8601String());

        Livewire::actingAs($this->user)
            ->test('pages::settings')
            ->set('tokenForm.token', self::TOKEN)
            ->set('tokenForm.expiresOn', now()->addDays(30)->toDateString())
            ->call('connectCloudflare')
            ->assertHasNoErrors();

        $this->assertSame(now()->addDays(30)->toDateString(), $this->user->cloudflareConnection->token_expires_on->toDateString());
    }

    public function test_expiry_can_be_changed_later(): void
    {
        CloudflareConnection::factory()->for($this->user)->create();

        Livewire::actingAs($this->user)
            ->test('pages::settings')
            ->call('editExpiry')
            ->set('tokenExpiresOn', '2027-04-01')
            ->call('saveExpiry')
            ->assertHasNoErrors()
            ->assertSee('Apr 1, 2027');
    }

    public function test_a_rotation_reminder_appears_two_weeks_before_expiry(): void
    {
        $connection = CloudflareConnection::factory()->for($this->user)->create(['token_expires_on' => now()->addDays(20)]);

        $this->actingAs($this->user)->get(route('domains.index'))->assertDontSeeText('Cloudflare API token expires');

        $connection->update(['token_expires_on' => now()->addDays(10)]);
        $this->actingAs($this->user)->get(route('domains.index'))->assertSeeText('Your Cloudflare API token expires in 10 days');

        $connection->update(['token_expires_on' => now()->subDay()]);
        $this->actingAs($this->user)->get(route('domains.index'))->assertSeeText('Your Cloudflare API token expired on');
    }

    private function fakeSuccessfulConnect(?string $expiresOn = null): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response($this->cloudflareResponse(array_filter(['id' => 'tok', 'status' => 'active', 'expires_on' => $expiresOn]))),
            'api.cloudflare.com/client/v4/accounts?*' => Http::response($this->cloudflareResponse([])),
            'api.cloudflare.com/client/v4/zones?*' => Http::response($this->cloudflareResponse([])),
        ]);
    }

    public function test_all_domains_can_be_cleared(): void
    {
        $connection = CloudflareConnection::factory()->for($this->user)->create(['last_synced_at' => now()]);
        $mine = Domain::factory()->count(3)->for($this->user)->create();
        DnsRecord::factory()->for($mine->first())->create();
        $someoneElses = Domain::factory()->create();

        Livewire::actingAs($this->user)
            ->test('pages::settings')
            ->set('confirmingClearDomains', true)
            ->assertSee('Remove all 3 domains?')
            ->call('clearDomains')
            ->assertDispatched('toast', message: 'Removed 3 domains');

        $this->assertSame(0, $this->user->domains()->count());
        $this->assertSame(0, DnsRecord::count());
        $this->assertModelExists($someoneElses);
        $this->assertNull($connection->fresh()->last_synced_at);
    }

    public function test_domains_cannot_be_cleared_mid_sync(): void
    {
        CloudflareConnection::factory()->for($this->user)->create(['sync_status' => 'running', 'sync_started_at' => now()]);
        Domain::factory()->for($this->user)->create();

        Livewire::actingAs($this->user)
            ->test('pages::settings')
            ->call('clearDomains');

        $this->assertSame(1, $this->user->domains()->count());
    }

    public function test_unverified_users_can_reach_settings_and_resend_the_link(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('settings'))
            ->assertOk()
            ->assertSeeText('Not verified.')
            ->assertSeeText('Available once your email is verified.')
            ->assertDontSeeText('Remove all domains');

        Livewire::actingAs($user)
            ->test('pages::settings')
            ->call('resendVerification')
            ->assertDispatched('toast', message: 'Sent a new link to '.$user->email);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_changing_email_requires_verifying_the_new_address(): void
    {
        Notification::fake();

        Livewire::actingAs($this->user)
            ->test('pages::settings')
            ->assertSee('Verified')
            ->set('email', 'new-address@example.com')
            ->call('saveProfile')
            ->assertDispatched('toast', message: 'Saved. Check new-address@example.com for a verification link.')
            ->assertSee('Not verified.');

        $this->assertFalse($this->user->fresh()->hasVerifiedEmail());
        Notification::assertSentTo($this->user, VerifyEmail::class);
        $this->actingAs($this->user->fresh())->get(route('domains.index'))->assertRedirect(route('verification.notice'));
    }

    public function test_unverified_users_cannot_connect_cloudflare(): void
    {
        $user = User::factory()->unverified()->create();

        Livewire::actingAs($user)
            ->test('pages::settings')
            ->set('tokenForm.token', self::TOKEN)
            ->call('connectCloudflare')
            ->assertDispatched('toast', message: 'Verify your email first');

        $this->assertNull($user->cloudflareConnection);
    }
}
