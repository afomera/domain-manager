<?php

namespace Tests\Feature;

use App\Models\BrowserSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class BrowserSessionsTest extends TestCase
{
    use RefreshDatabase;

    private const string SAFARI_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/27.0 Safari/605.1.15';

    private const string CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36';

    private const string EDGE_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        $this->user = User::factory()->create(['password' => 'password']);
    }

    private function addSession(string $id, ?int $userId, string $agent, string $ip = '203.0.113.5', int $minutesAgo = 5): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => $ip,
            'user_agent' => $agent,
            'payload' => '',
            'last_activity' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);
    }

    public function test_user_agents_are_described_in_plain_words(): void
    {
        $describe = fn (string $agent) => (new BrowserSession)->forceFill(['user_agent' => $agent]);

        $this->assertSame('Safari on macOS', $describe(self::SAFARI_MAC)->description());
        $this->assertSame('Chrome on Android', $describe(self::CHROME_ANDROID)->description());
        $this->assertTrue($describe(self::CHROME_ANDROID)->isMobile());
        $this->assertSame('Edge on Windows', $describe(self::EDGE_WINDOWS)->description());
        $this->assertFalse($describe(self::EDGE_WINDOWS)->isMobile());
        $this->assertSame('Unknown browser', $describe('curl/8.0')->description());
    }

    public function test_settings_lists_only_your_sessions_with_the_current_one_marked(): void
    {
        $page = Livewire::actingAs($this->user)->test('pages::settings');
        $this->addSession(session()->getId(), $this->user->id, self::SAFARI_MAC, '127.0.0.1', 0);
        $this->addSession('phone-session', $this->user->id, self::CHROME_ANDROID, '198.51.100.7', 30);
        $this->addSession('someone-else', User::factory()->create()->id, self::EDGE_WINDOWS);

        $page->call('$refresh')
            ->assertSeeInOrder(['Safari on macOS', 'This device', 'Chrome on Android', '198.51.100.7'])
            ->assertDontSee('Edge on Windows');
    }

    public function test_other_sessions_can_be_logged_out_with_your_password(): void
    {
        $page = Livewire::actingAs($this->user)->test('pages::settings');
        $this->addSession(session()->getId(), $this->user->id, self::SAFARI_MAC);
        $this->addSession('phone-session', $this->user->id, self::CHROME_ANDROID);
        $this->addSession('someone-else', User::factory()->create()->id, self::EDGE_WINDOWS);
        $originalHash = $this->user->fresh()->password;

        $page->set('confirmingLogoutOthers', true)
            ->set('logoutPassword', 'password')
            ->call('logoutOtherSessions')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Logged out of 1 other session')
            ->assertSet('logoutPassword', '');

        $this->assertSame([session()->getId()], BrowserSession::whereBelongsTo($this->user)->pluck('id')->all());
        $this->assertTrue(BrowserSession::whereKey('someone-else')->exists());

        // Rehashed, so "remember me" cookies on other devices stop working too.
        $this->assertNotSame($originalHash, $this->user->fresh()->password);
        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_a_wrong_password_logs_nobody_out(): void
    {
        $page = Livewire::actingAs($this->user)->test('pages::settings');
        $this->addSession('phone-session', $this->user->id, self::CHROME_ANDROID);

        $page->set('logoutPassword', 'nope')
            ->call('logoutOtherSessions')
            ->assertHasErrors(['logoutPassword' => 'current_password']);

        $this->assertTrue(BrowserSession::whereKey('phone-session')->exists());
    }
}
