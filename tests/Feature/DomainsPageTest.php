<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DomainsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('domains.index'))->assertRedirect(route('login'));
    }

    public function test_index_summarizes_the_portfolio_and_flags_domains_expiring_without_renewal(): void
    {
        Domain::factory()->for($this->user)->create(['name' => 'safe.dev', 'renewal_price_cents' => 1000]);
        Domain::factory()->for($this->user)->onSquarespace()->expiringWithoutRenewal(5)->create(['name' => 'lapsing.net']);

        $this->actingAs($this->user)
            ->get(route('domains.index'))
            ->assertOk()
            ->assertSeeText('2 domains · 1 on Cloudflare, 1 left on Squarespace · $30.00/yr in renewals')
            ->assertSeeText('lapsing.net expires')
            ->assertSeeText('Expires in 5 days');
    }

    public function test_index_only_lists_the_users_own_domains(): void
    {
        Domain::factory()->for($this->user)->create(['name' => 'mine.dev']);
        Domain::factory()->create(['name' => 'theirs.dev']);

        $this->actingAs($this->user)
            ->get(route('domains.index'))
            ->assertSeeText('mine.dev')
            ->assertDontSeeText('theirs.dev');
    }

    public function test_index_filters_by_registrar_and_search(): void
    {
        Domain::factory()->for($this->user)->create(['name' => 'alpha.dev']);
        Domain::factory()->for($this->user)->onSquarespace()->create(['name' => 'beta.com']);
        Domain::factory()->for($this->user)->onSquarespace()->create(['name' => 'gamma.com']);

        Livewire::actingAs($this->user)
            ->test('pages::domains.index')
            ->call('filter', 'registrar', 'squarespace')
            ->assertSee('beta.com')
            ->assertSee('gamma.com')
            ->assertDontSee('alpha.dev')
            ->set('search', 'gam')
            ->assertSee('gamma.com')
            ->assertDontSee('beta.com');
    }

    public function test_index_search_treats_wildcards_literally(): void
    {
        Domain::factory()->for($this->user)->create(['name' => 'alpha.dev']);

        Livewire::actingAs($this->user)
            ->test('pages::domains.index')
            ->set('search', '%')
            ->assertDontSee('alpha.dev')
            ->assertSee('No domains match');
    }

    public function test_domains_without_known_dates_still_render(): void
    {
        Domain::factory()->for($this->user)->create(['name' => 'mystery.dev', 'registered_on' => null, 'expires_on' => null, 'renewal_price_cents' => null]);

        $this->actingAs($this->user)->get(route('domains.index'))->assertOk()->assertSeeText('mystery.dev');
        $this->actingAs($this->user)->get('/domains/mystery.dev?tab=details')->assertOk();
    }

    public function test_show_page_defaults_to_details_when_dns_is_not_on_cloudflare(): void
    {
        $domain = Domain::factory()->for($this->user)->onSquarespace()->create(['name' => 'oldsite.net']);

        $this->actingAs($this->user)
            ->get(route('domains.show', $domain))
            ->assertOk()
            ->assertSeeText('Move to Cloudflare')
            ->assertSeeText('DNS for this domain is hosted at Squarespace')
            ->assertDontSeeText('DNS records');
    }

    public function test_show_page_shows_dns_records_for_cloudflare_zones(): void
    {
        $domain = Domain::factory()->for($this->user)->syncedFromCloudflare()->create(['name' => 'zone.dev']);
        $domain->dnsRecords()->create(['type' => 'A', 'name' => '@', 'content' => '192.0.2.10']);

        $this->actingAs($this->user)
            ->get(route('domains.show', $domain))
            ->assertOk()
            ->assertSeeText('DNS records')
            ->assertSeeText('192.0.2.10')
            ->assertSeeText('Changes apply immediately through the Cloudflare API');
    }

    public function test_another_users_domain_is_not_found(): void
    {
        $domain = Domain::factory()->create(['name' => 'theirs.dev']);

        $this->actingAs($this->user)->get(route('domains.show', $domain))->assertNotFound();
    }

    public function test_two_users_can_track_the_same_domain_name(): void
    {
        Domain::factory()->create(['name' => 'shared.dev', 'note' => 'Theirs']);
        Domain::factory()->for($this->user)->create(['name' => 'shared.dev', 'note' => 'Mine']);

        $this->actingAs($this->user)
            ->get('/domains/shared.dev?tab=details')
            ->assertOk()
            ->assertSeeText('Mine')
            ->assertDontSeeText('Theirs');
    }

    public function test_show_page_returns_not_found_for_unknown_domains(): void
    {
        $this->actingAs($this->user)->get('/domains/missing.example')->assertNotFound();
    }

    public function test_recently_registered_domains_are_transfer_locked(): void
    {
        $domain = Domain::factory()->onSquarespace()->create(['registered_on' => now()->subDays(10)]);

        $this->assertNotNull($domain->transferLockedUntil());
        $this->assertStringStartsWith('Transfer locked until', $domain->statusNote());
    }

    public function test_header_filters_narrow_the_list(): void
    {
        Domain::factory()->for($this->user)->create(['name' => 'renewing.dev', 'auto_renew' => true, 'expires_on' => now()->addDays(200)]);
        Domain::factory()->for($this->user)->expiringWithoutRenewal(10)->create(['name' => 'lapsing.dev']);
        Domain::factory()->for($this->user)->readyToTransfer()->create(['name' => 'moving.com', 'expires_on' => now()->addDays(60)]);

        $page = Livewire::actingAs($this->user)->test('pages::domains.index');
        $listed = fn () => $page->instance()->domains->pluck('name')->sort()->values()->all();

        $page->call('filter', 'renew', 'off')->assertSee('Auto‑renew:');
        $this->assertSame(['lapsing.dev'], $listed());

        $page->call('filter', 'renew', '')->call('filter', 'expires', '90');
        $this->assertSame(['lapsing.dev', 'moving.com'], $listed());

        $page->call('filter', 'status', 'ready');
        $this->assertSame(['moving.com'], $listed());

        $page->call('clearFilters')->assertSet('expires', '')->assertSet('status', '');
        $this->assertCount(3, $listed());
    }

    public function test_unknown_filter_values_are_ignored(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::domains.index')
            ->call('filter', 'renew', 'sideways')
            ->assertSet('renew', '')
            ->call('filter', 'adding', '1')
            ->assertSet('adding', false);
    }

    public function test_sorting_puts_unknown_values_last_in_either_direction(): void
    {
        Domain::factory()->for($this->user)->create(['name' => 'cheap.dev', 'renewal_price_cents' => 1000]);
        Domain::factory()->for($this->user)->create(['name' => 'pricey.dev', 'renewal_price_cents' => 65000]);
        Domain::factory()->for($this->user)->create(['name' => 'unpriced.dev', 'renewal_price_cents' => null]);

        Livewire::actingAs($this->user)
            ->test('pages::domains.index')
            ->call('sortBy', 'price', 'desc')
            ->assertSeeInOrder(['pricey.dev', 'cheap.dev', 'unpriced.dev'])
            ->call('sortBy', 'price', 'asc')
            ->assertSeeInOrder(['cheap.dev', 'pricey.dev', 'unpriced.dev']);
    }

    public function test_search_matches_notes_and_registrars(): void
    {
        Domain::factory()->for($this->user)->create(['name' => 'alpha.dev', 'note' => 'Client site for Bakery']);
        Domain::factory()->for($this->user)->onSquarespace()->create(['name' => 'beta.dev']);

        Livewire::actingAs($this->user)
            ->test('pages::domains.index')
            ->set('search', 'bakery')
            ->assertSee('alpha.dev')
            ->assertDontSee('beta.dev')
            ->set('search', 'squarespace')
            ->assertSee('beta.dev')
            ->assertDontSee('alpha.dev');
    }

    public function test_filtering_shows_how_many_domains_match_and_each_option_is_counted(): void
    {
        Domain::factory()->for($this->user)->count(2)->expiringWithoutRenewal(10)->create();
        Domain::factory()->for($this->user)->onSquarespace()->expiringWithoutRenewal(40)->create();
        Domain::factory()->for($this->user)->count(3)->create(['auto_renew' => true]);

        $page = Livewire::actingAs($this->user)
            ->test('pages::domains.index')
            ->call('filter', 'renew', 'off')
            ->assertSeeHtml('<span class="font-medium text-fg">3</span> of 6 domains match');

        $counts = $page->instance()->filterCounts;
        $this->assertSame(3, $counts['renew']['off']);
        $this->assertSame(3, $counts['renew']['on']);
        $this->assertSame(6, $counts['renew']['']);

        // Other columns' counts respect the active auto-renew filter.
        $this->assertSame(2, $counts['registrar']['cloudflare']);
        $this->assertSame(1, $counts['registrar']['squarespace']);
        $this->assertSame(2, $counts['expires']['30']);
    }

    public function test_sort_and_filters_are_remembered_between_visits(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::domains.index')
            ->call('filter', 'renew', 'off')
            ->call('filter', 'registrar', 'squarespace')
            ->call('sortBy', 'price', 'desc')
            ->set('search', 'not remembered');

        Livewire::actingAs($this->user)
            ->test('pages::domains.index')
            ->assertSet('renew', 'off')
            ->assertSet('registrar', 'squarespace')
            ->assertSet('sort', 'price')
            ->assertSet('direction', 'desc')
            ->assertSet('search', '');
    }

    public function test_filters_in_the_url_win_over_remembered_ones(): void
    {
        session()->put('domains.view', ['renew' => 'off', 'sort' => 'price', 'direction' => 'desc']);

        Livewire::actingAs($this->user)
            ->withQueryParams(['status' => 'ready'])
            ->test('pages::domains.index')
            ->assertSet('status', 'ready')
            ->assertSet('renew', '')
            ->assertSet('sort', 'expires');
    }

    public function test_clearing_filters_is_remembered_too(): void
    {
        session()->put('domains.view', ['renew' => 'off']);

        Livewire::actingAs($this->user)->test('pages::domains.index')->assertSet('renew', 'off')->call('clearFilters');

        Livewire::actingAs($this->user)->test('pages::domains.index')->assertSet('renew', '');
    }

    public function test_junk_in_the_remembered_view_is_ignored(): void
    {
        session()->put('domains.view', ['renew' => 'sideways', 'sort' => 'adding', 'registrar' => ['nope']]);

        Livewire::actingAs($this->user)
            ->test('pages::domains.index')
            ->assertSet('renew', '')
            ->assertSet('sort', 'expires')
            ->assertSet('registrar', 'all');
    }
}
