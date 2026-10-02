<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sign_in_page_renders(): void
    {
        $this->get(route('login'))->assertOk()->assertSeeText('Sign in');
    }

    public function test_users_can_sign_in_and_out(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('domains.index'));
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'));
        $this->assertGuest();
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_new_users_register_with_a_single_password_and_get_a_verification_email(): void
    {
        Notification::fake();

        $this->post(route('register'), [
            'name' => 'Andrea',
            'email' => 'new@example.com',
            'password' => 'a-strong-password',
        ])->assertSessionHasNoErrors();

        $user = User::firstWhere('email', 'new@example.com');
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_short_passwords_are_rejected_at_sign_up(): void
    {
        $this->post(route('register'), ['name' => 'A', 'email' => 'a@example.com', 'password' => 'short'])
            ->assertSessionHasErrors('password');
    }

    public function test_unverified_users_are_sent_to_confirm_their_email(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('domains.index'))->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get(route('verification.notice'))->assertRedirect(route('onboarding'));
        $this->actingAs($user)->get(route('onboarding'))->assertOk()->assertSeeText('Confirm your email')->assertSeeText($user->email);
    }

    public function test_the_emailed_link_verifies_the_account(): void
    {
        $user = User::factory()->unverified()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($link)
            ->assertRedirect(route('domains.index'))
            ->assertSessionHas('toast', 'Email verified');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        // The flashed toast is shown on arrival.
        $this->actingAs($user->fresh())
            ->withSession(['toast' => 'Email verified'])
            ->get(route('domains.index'))
            ->assertOk()
            ->assertSee('x-init="show(', false);
    }

    public function test_the_verification_email_can_be_resent(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'))->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_postmark_is_available_as_a_mailer(): void
    {
        config(['services.postmark.key' => 'test-server-token']);

        $this->assertInstanceOf(PostmarkApiTransport::class, Mail::mailer('postmark')->getSymfonyTransport());
    }
}
