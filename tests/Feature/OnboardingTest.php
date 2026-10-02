<?php

namespace Tests\Feature;

use App\Jobs\SyncCloudflareConnection;
use App\Models\CloudflareConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN = 'cf-live-token-abcdefghijklmnopqrstuvwxyz';

    public function test_a_new_account_starts_by_confirming_its_email(): void
    {
        $this->post(route('register'), ['name' => 'Andrea', 'email' => 'new@example.com', 'password' => 'a-strong-password']);

        $this->followingRedirects()
            ->get(route('domains.index'))
            ->assertSeeText('Confirm your email')
            ->assertSeeText('new@example.com');
    }

    public function test_verified_accounts_skip_straight_to_cloudflare(): void
    {
        $user = User::factory()->notOnboarded()->create();

        $this->actingAs($user)->get(route('domains.index'))->assertRedirect(route('onboarding'));
        $this->actingAs($user)->get(route('onboarding'))->assertSeeText('Connect Cloudflare')->assertDontSeeText('Confirm your email');
    }

    public function test_the_email_step_moves_on_once_the_link_is_clicked_elsewhere(): void
    {
        $user = User::factory()->unverified()->notOnboarded()->create();

        $page = Livewire::actingAs($user)->test('pages::onboarding')->assertSee('Confirm your email');

        $user->markEmailAsVerified();

        $page->call('checkVerified')
            ->assertDispatched('toast', message: 'Email verified')
            ->assertSee('Connect Cloudflare');
    }

    public function test_the_verification_link_leads_back_into_onboarding(): void
    {
        $user = User::factory()->unverified()->notOnboarded()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($link)
            ->assertRedirect(route('onboarding'))
            ->assertSessionHas('toast', 'Email verified');
    }

    public function test_connecting_cloudflare_starts_the_import_and_finishes_onboarding(): void
    {
        Queue::fake();
        Http::fake(['api.cloudflare.com/client/v4/user/tokens/verify' => Http::response($this->cloudflareResponse(['status' => 'active']))]);
        $user = User::factory()->notOnboarded()->create();

        Livewire::actingAs($user)
            ->test('pages::onboarding')
            ->set('tokenForm.token', self::TOKEN)
            ->set('tokenForm.expiresOn', now()->addDays(90)->toDateString())
            ->call('connect')
            ->assertHasNoErrors()
            ->assertRedirect(route('domains.index'));

        $user->refresh();
        $this->assertTrue($user->isOnboarded());
        $this->assertSame(now()->addDays(90)->toDateString(), $user->cloudflareConnection->token_expires_on->toDateString());
        $this->assertNotSame(self::TOKEN, DB::table('cloudflare_connections')->value('api_token'));
        Queue::assertPushed(SyncCloudflareConnection::class);
    }

    public function test_a_rejected_token_keeps_you_on_the_step(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->cloudflareError('Invalid API Token'), 401)]);
        $user = User::factory()->notOnboarded()->create();

        Livewire::actingAs($user)
            ->test('pages::onboarding')
            ->set('tokenForm.token', self::TOKEN)
            ->call('connect')
            ->assertHasErrors('tokenForm.token')
            ->assertNoRedirect();

        $this->assertFalse($user->fresh()->isOnboarded());
    }

    public function test_an_existing_connection_is_reused(): void
    {
        Queue::fake();
        $user = User::factory()->notOnboarded()->create();
        CloudflareConnection::factory()->for($user)->create(['last_synced_at' => now()]);

        Livewire::actingAs($user)
            ->test('pages::onboarding')
            ->assertSee('Already connected')
            ->call('continueWithConnection')
            ->assertRedirect(route('domains.index'));

        $this->assertTrue($user->fresh()->isOnboarded());
        Queue::assertNothingPushed();
    }

    public function test_cloudflare_can_be_skipped(): void
    {
        $user = User::factory()->notOnboarded()->create();

        Livewire::actingAs($user)->test('pages::onboarding')->call('skip')->assertRedirect(route('domains.index'));

        $this->assertTrue($user->fresh()->isOnboarded());
        $this->actingAs($user->fresh())->get(route('domains.index'))->assertOk();
    }

    public function test_steps_cannot_be_skipped_before_email_is_confirmed(): void
    {
        $user = User::factory()->unverified()->notOnboarded()->create();

        Livewire::actingAs($user)->test('pages::onboarding')->call('skip')->assertNoRedirect();

        $this->assertFalse($user->fresh()->isOnboarded());
    }

    public function test_finished_accounts_are_sent_to_their_domains(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::onboarding')->assertRedirect(route('domains.index'));
    }

    public function test_onboarding_can_be_restarted_from_settings_locally(): void
    {
        $this->app['env'] = 'local';
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings')
            ->assertSee('Restart onboarding')
            ->call('restartOnboarding')
            ->assertRedirect(route('onboarding'));

        $this->assertFalse($user->fresh()->isOnboarded());
    }

    public function test_restarting_onboarding_is_not_available_outside_local(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings')
            ->assertDontSee('Restart onboarding')
            ->call('restartOnboarding')
            ->assertNotFound();

        $this->assertTrue($user->fresh()->isOnboarded());
    }
}
