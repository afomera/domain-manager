<?php

namespace Tests\Feature;

use App\Enums\Provider;
use App\Models\CloudflareConnection;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class DomainEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_adding_a_domain_fills_dates_from_rdap(): void
    {
        Http::fake(['rdap.org/domain/pagewright.co' => Http::response([
            'events' => [
                ['eventAction' => 'registration', 'eventDate' => '2020-10-09T00:00:00Z'],
                ['eventAction' => 'expiration', 'eventDate' => '2026-10-09T00:00:00Z'],
            ],
            'entities' => [['roles' => ['registrar'], 'vcardArray' => ['vcard', [['fn', [], 'text', 'Squarespace Domains II LLC']]]]],
        ])]);

        Livewire::actingAs($this->user)
            ->test('domains.domain-editor')
            ->set('form.name', ' PageWright.co. ')
            ->set('form.registrar', 'squarespace')
            ->set('form.renewal_price', '$30')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('domains.show', 'pagewright.co'));

        $domain = $this->user->domains()->sole();
        $this->assertSame('pagewright.co', $domain->name);
        $this->assertSame(Provider::Squarespace, $domain->registrar);
        $this->assertSame('2026-10-09', $domain->expires_on->toDateString());
        $this->assertSame(3000, $domain->renewal_price_cents);
    }

    public function test_a_domain_cannot_be_added_twice(): void
    {
        Domain::factory()->for($this->user)->create(['name' => 'dupe.com']);

        Livewire::actingAs($this->user)
            ->test('domains.domain-editor')
            ->set('form.name', 'dupe.com')
            ->set('form.expires_on', '2027-01-01')
            ->set('form.registered_on', '2020-01-01')
            ->call('save')
            ->assertHasErrors('form.name');
    }

    public function test_cloudflare_registrar_details_are_not_editable_by_hand(): void
    {
        $domain = Domain::factory()->for($this->user)->syncedFromCloudflare()->create(['expires_on' => '2027-03-14', 'auto_renew' => true]);

        Livewire::actingAs($this->user)
            ->test('domains.domain-editor', ['domain' => $domain])
            ->set('form.expires_on', '2030-01-01')
            ->set('form.auto_renew', false)
            ->set('form.renewal_price', '12.20')
            ->call('save')
            ->assertHasNoErrors();

        $domain->refresh();
        $this->assertSame('2027-03-14', $domain->expires_on->toDateString());
        $this->assertTrue($domain->auto_renew);
        $this->assertSame(1220, $domain->renewal_price_cents);
    }

    public function test_other_users_cannot_edit_a_domain(): void
    {
        $domain = Domain::factory()->create();

        Livewire::actingAs($this->user)
            ->test('domains.domain-editor', ['domain' => $domain])
            ->assertForbidden();
    }

    public function test_auto_renew_is_turned_on_through_cloudflare_registrar(): void
    {
        $domain = Domain::factory()->for($this->user)->syncedFromCloudflare()->create(['name' => 'quietmetrics.com', 'auto_renew' => false, 'cloudflare_account_id' => 'acc_1']);
        CloudflareConnection::factory()->for($this->user)->create();

        Http::fake([
            'api.cloudflare.com/client/v4/accounts/acc_1/registrar/registrations/quietmetrics.com' => Http::response($this->cloudflareResponse(['completed' => true, 'state' => 'succeeded'])),
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::domains.show', ['domain' => $domain])
            ->call('setAutoRenew', true)
            ->assertSet('autoRenewError', null);

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request['auto_renew'] === true);
        $this->assertTrue($domain->fresh()->auto_renew);
    }

    public function test_auto_renew_for_other_registrars_is_recorded_here(): void
    {
        $domain = Domain::factory()->for($this->user)->onSquarespace()->create(['auto_renew' => false]);

        Livewire::actingAs($this->user)
            ->test('pages::domains.show', ['domain' => $domain])
            ->assertSee('Tracked here · change it at Squarespace')
            ->call('setAutoRenew', true)
            ->assertDispatched('toast', message: 'Saved here — also turn it on at Squarespace');

        Http::assertNothingSent();
        $this->assertTrue($domain->fresh()->auto_renew);
    }

    public function test_edit_details_opens_the_editor_from_any_tab(): void
    {
        $domain = Domain::factory()->for($this->user)->syncedFromCloudflare()->create();

        Livewire::actingAs($this->user)
            ->test('pages::domains.show', ['domain' => $domain])
            ->assertSet('tab', 'dns')
            ->assertSee('Auto‑renew')
            ->call('editDetails')
            ->assertSet('tab', 'details')
            ->assertSet('editing', true)
            ->assertSee('Renewal price (USD/yr)');
    }
}
