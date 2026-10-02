<?php

namespace Tests\Feature;

use App\Jobs\ImportZoneRecords;
use App\Jobs\LookUpDomainRegistrar;
use App\Jobs\SyncCloudflareConnection;
use App\Models\CloudflareConnection;
use App\Services\Cloudflare\CloudflareSync;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SyncProgressTest extends TestCase
{
    use RefreshDatabase;

    private CloudflareConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = CloudflareConnection::factory()->create();
    }

    public function test_sync_runs_in_the_background_and_shows_progress(): void
    {
        Queue::fake();

        Livewire::actingAs($this->connection->user)
            ->test('pages::domains.index')
            ->call('sync')
            ->assertSet('watchingSync', true)
            ->assertSee('Importing your domains from Cloudflare')
            ->assertSee('Waiting to start…')
            ->assertSee('Syncing…');

        Queue::assertPushed(SyncCloudflareConnection::class, fn ($job) => $job->cloudflareConnection->is($this->connection));
        $this->assertSame('queued', $this->connection->fresh()->sync_status);
    }

    public function test_a_second_sync_is_not_queued_while_one_runs(): void
    {
        Queue::fake();

        $this->connection->queueSync();
        $this->connection->queueSync();

        Queue::assertPushed(SyncCloudflareConnection::class, 1);
    }

    public function test_the_page_announces_when_a_watched_sync_finishes(): void
    {
        $this->connection->update(['sync_status' => 'running', 'sync_started_at' => now(), 'sync_progress' => ['message' => 'Importing DNS records for a.dev', 'percent' => 40]]);

        $page = Livewire::actingAs($this->connection->user)
            ->test('pages::domains.index')
            ->assertSet('watchingSync', true)
            ->assertSee('Importing DNS records for a.dev')
            ->assertSee('40%');

        $this->connection->update(['sync_status' => null, 'sync_progress' => null, 'last_synced_at' => now()]);

        $page->call('checkSync')
            ->assertSet('watchingSync', false)
            ->assertDispatched('toast', message: 'Synced 0 domains')
            ->assertDontSee('Syncing…');
    }

    public function test_a_sync_stuck_in_the_queue_hints_at_the_missing_worker(): void
    {
        $this->connection->update(['sync_status' => 'queued', 'sync_started_at' => now()->subMinute(), 'sync_progress' => ['message' => 'Waiting to start…', 'percent' => 0]]);

        Livewire::actingAs($this->connection->user)
            ->test('pages::domains.index')
            ->assertSee('is a worker running?');
    }

    public function test_a_stale_sync_no_longer_counts_as_running(): void
    {
        $this->connection->update(['sync_status' => 'running', 'sync_started_at' => now()->subHour()]);

        $this->assertFalse($this->connection->isSyncing());
    }

    public function test_tracked_runs_report_progress_and_clear_their_status(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones?*' => Http::response($this->cloudflareResponse([
                ['id' => 'zone_a', 'name' => 'a.dev', 'status' => 'active', 'account' => ['id' => 'acc_1', 'name' => 'Personal']],
            ])),
            'api.cloudflare.com/client/v4/accounts?*' => Http::response($this->cloudflareResponse([['id' => 'acc_1', 'name' => 'Personal']])),
            'api.cloudflare.com/client/v4/accounts/acc_1/registrar/registrations*' => Http::response($this->cloudflareResponse([], ['cursor' => ''])),
            'api.cloudflare.com/client/v4/zones/zone_a/dns_records*' => Http::response($this->cloudflareResponse([])),
            'rdap.org/*' => Http::response(null, 404),
        ]);

        $steps = [];
        app(CloudflareSync::class)->run($this->connection, function (string $message, float $percent) use (&$steps) {
            $steps[] = [$message, $percent];
        });

        $this->assertSame('Fetching zones', $steps[0][0]);
        $this->assertContains('Importing DNS records for a.dev', array_column($steps, 0));
        $this->assertSame(100.0, (float) end($steps)[1]);
        $this->assertSame(array_column($steps, 1), collect(array_column($steps, 1))->sort()->values()->all(), 'Progress never goes backwards.');

        SyncCloudflareConnection::dispatchSync($this->connection);
        $connection = $this->connection->fresh();
        $this->assertNull($connection->sync_status);
        $this->assertNull($connection->sync_progress);
        $this->assertNull($connection->sync_batch_id);
        $this->assertNotNull($connection->last_synced_at);
        $this->assertSame(1, $connection->user->domains()->count());
    }

    public function test_every_sync_job_fits_inside_managed_queue_time_limits(): void
    {
        $jobs = [new SyncCloudflareConnection($this->connection), new ImportZoneRecords(1), new LookUpDomainRegistrar(1, now()->toIso8601String())];

        foreach ($jobs as $job) {
            $this->assertLessThanOrEqual(60, $job->timeout, $job::class.' must finish within a Flex managed queue job limit.');
            $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'), 'A running job must never be handed to a second worker.');
        }
    }

    public function test_the_slow_part_fans_out_as_a_batch_of_small_jobs(): void
    {
        Bus::fake();
        Http::fake([
            'api.cloudflare.com/client/v4/zones?*' => Http::response($this->cloudflareResponse([
                ['id' => 'zone_a', 'name' => 'a.dev', 'status' => 'active', 'account' => ['id' => 'acc_1', 'name' => 'Personal']],
                ['id' => 'zone_b', 'name' => 'b.dev', 'status' => 'active', 'account' => ['id' => 'acc_1', 'name' => 'Personal']],
            ])),
            'api.cloudflare.com/client/v4/accounts?*' => Http::response($this->cloudflareResponse([['id' => 'acc_1', 'name' => 'Personal']])),
            'api.cloudflare.com/client/v4/accounts/acc_1/registrar/registrations*' => Http::response($this->cloudflareResponse([], ['cursor' => ''])),
        ]);

        (new SyncCloudflareConnection($this->connection))->handle(app(CloudflareSync::class));

        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->whereInstanceOf(ImportZoneRecords::class)->count() === 2
            && $batch->jobs->whereInstanceOf(LookUpDomainRegistrar::class)->count() === 2);
        $this->assertSame('running', $this->connection->fresh()->sync_status);
    }

    public function test_failed_pieces_become_a_warning_when_the_batch_finishes(): void
    {
        $this->connection->update(['sync_status' => 'running', 'sync_started_at' => now(), 'sync_batch_id' => 'abc']);

        SyncCloudflareConnection::complete($this->connection->id, now()->toIso8601String(), [], failures: 2);

        $connection = $this->connection->fresh();
        $this->assertNull($connection->sync_status);
        $this->assertNull($connection->sync_batch_id);
        $this->assertStringContainsString('2 zones or lookups couldn’t be synced', $connection->last_sync_error);
    }

    public function test_a_stranded_sync_cannot_hold_the_lock_forever(): void
    {
        $job = new SyncCloudflareConnection($this->connection);

        $this->assertGreaterThan(0, $job->uniqueFor);
        $this->assertLessThanOrEqual(CloudflareConnection::SYNC_STALE_AFTER_MINUTES * 60, $job->uniqueFor, 'The lock must expire by the time the UI treats the sync as stale.');
    }

    public function test_a_sync_waiting_for_a_worker_can_be_cancelled(): void
    {
        Queue::fake();
        $this->connection->queueSync();
        $this->travel(1)->minutes();

        Livewire::actingAs($this->connection->user)
            ->test('pages::domains.index')
            ->assertSee('is a worker running?')
            ->call('cancelSync')
            ->assertDispatched('toast', message: 'Sync cancelled')
            ->assertSet('watchingSync', false)
            ->assertDontSee('Syncing…');

        $this->assertNull($this->connection->fresh()->sync_status);

        // The lock is released, so the next sync queues straight away.
        $this->connection->fresh()->queueSync();
        Queue::assertPushed(SyncCloudflareConnection::class, 2);
    }

    public function test_a_running_sync_is_not_cancelled(): void
    {
        $this->connection->update(['sync_status' => 'running', 'sync_started_at' => now()]);

        $this->connection->cancelQueuedSync();

        $this->assertSame('running', $this->connection->fresh()->sync_status);
    }
}
