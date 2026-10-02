<?php

namespace Tests\Feature;

use App\Events\PortfolioUpdated;
use App\Models\CloudflareConnection;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class LiveUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Event::fake([PortfolioUpdated::class]);
    }

    public function test_adding_editing_and_removing_a_domain_is_broadcast(): void
    {
        Livewire::actingAs($this->user)
            ->test('domains.domain-editor')
            ->set('form.name', 'fresh.dev')
            ->set('form.registered_on', '2024-01-01')
            ->set('form.expires_on', '2027-01-01')
            ->call('save');

        Event::assertDispatched(PortfolioUpdated::class, fn ($event) => $event->userId === $this->user->id && $event->domain === 'fresh.dev' && ! $event->removed);

        $domain = $this->user->domains()->sole();

        Livewire::actingAs($this->user)
            ->test('domains.domain-editor', ['domain' => $domain])
            ->call('delete');

        Event::assertDispatched(PortfolioUpdated::class, fn ($event) => $event->domain === 'fresh.dev' && $event->removed);
    }

    public function test_dns_changes_and_auto_renew_are_broadcast(): void
    {
        $domain = Domain::factory()->for($this->user)->onSquarespace()->create(['name' => 'local.dev']);
        $record = DnsRecord::factory()->for($domain)->create();

        Livewire::actingAs($this->user)
            ->test('domains.dns-records', ['domain' => $domain])
            ->call('edit', $record->id)
            ->call('delete');

        Livewire::actingAs($this->user)
            ->test('pages::domains.show', ['domain' => $domain])
            ->call('setAutoRenew', true);

        Event::assertDispatchedTimes(PortfolioUpdated::class, 2);
    }

    public function test_clearing_all_domains_tells_every_tab_they_are_gone(): void
    {
        Domain::factory()->for($this->user)->create();

        Livewire::actingAs($this->user)->test('pages::settings')->call('clearDomains');

        Event::assertDispatched(PortfolioUpdated::class, fn ($event) => $event->domain === null && $event->removed);
    }

    public function test_starting_a_sync_is_broadcast(): void
    {
        Queue::fake();

        CloudflareConnection::factory()->for($this->user)->create()->queueSync();

        Event::assertDispatched(PortfolioUpdated::class, fn ($event) => $event->userId === $this->user->id);
    }

    public function test_the_event_goes_to_the_users_private_channel(): void
    {
        $event = new PortfolioUpdated($this->user->id, 'a.dev', removed: true);

        $this->assertEquals([new PrivateChannel('App.Models.User.'.$this->user->id)], $event->broadcastOn());
        $this->assertSame('portfolio.updated', $event->broadcastAs());
        $this->assertSame(['domain' => 'a.dev', 'removed' => true], $event->broadcastWith());
    }

    public function test_users_can_only_subscribe_to_their_own_channel(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        // Channel rules register on the broadcaster active at boot (null in tests), so register them on Reverb too.
        require base_path('routes/channels.php');

        $auth = fn (int $userId) => $this->actingAs($this->user)->post('/broadcasting/auth', [
            'channel_name' => 'private-App.Models.User.'.$userId,
            'socket_id' => '1234.5678',
        ]);

        $auth($this->user->id)->assertOk();
        $auth(User::factory()->create()->id)->assertForbidden();
    }
}
