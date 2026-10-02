<?php

use App\Events\PortfolioUpdated;
use App\Models\Domain;
use App\Services\Cloudflare\CloudflareException;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public Domain $domain;

    #[Url]
    public ?string $tab = null;

    public bool $editing = false;

    public ?string $autoRenewError = null;

    public function mount(): void
    {
        $this->authorize('view', $this->domain);

        if (! in_array($this->tab, $this->availableTabs(), true)) {
            $this->tab = $this->availableTabs()[0];
        }
    }

    public function showTab(string $tab): void
    {
        if (in_array($tab, $this->availableTabs(), true)) {
            $this->tab = $tab;
        }
    }

    /**
     * Re-render so the record count in the tab stays current.
     */
    #[On('dns-records-changed')]
    public function refreshRecordCount(): void {}

    /**
     * Something changed this domain in another tab or device.
     */
    #[On('portfolio-refreshed')]
    public function refreshFromElsewhere(): void
    {
        $this->domain->refresh();
    }

    #[On('domain-updated')]
    public function closeEditor(): void
    {
        $this->editing = false;
        $this->domain->refresh();
    }

    /**
     * Cloudflare Registrar domains change at Cloudflare. Other registrars have no API,
     * so the switch records what you've set there.
     */
    public function setAutoRenew(bool $autoRenew): void
    {
        $this->authorize('update', $this->domain);
        $this->autoRenewError = null;

        if ($this->domain->isRegisteredWithCloudflare()) {
            try {
                $this->domain->cloudflareClient()->setAutoRenew($this->domain->cloudflare_account_id, $this->domain->name, $autoRenew);
            } catch (CloudflareException $exception) {
                $this->autoRenewError = 'Cloudflare: '.$exception->getMessage();

                return;
            }

            $this->domain->update(['auto_renew' => $autoRenew]);
            PortfolioUpdated::dispatch($this->domain->user_id, $this->domain->name);
            $this->dispatch('toast', message: 'Auto‑renew turned '.($autoRenew ? 'on' : 'off').' at Cloudflare');

            return;
        }

        $this->domain->update(['auto_renew' => $autoRenew]);
        PortfolioUpdated::dispatch($this->domain->user_id, $this->domain->name);
        $this->dispatch('toast', message: 'Saved here — also turn it '.($autoRenew ? 'on' : 'off').' at '.$this->domain->registrarLabel());
    }

    /**
     * Jump straight to the editor from the header, whichever tab is open.
     */
    public function editDetails(): void
    {
        $this->tab = 'details';
        $this->editing = true;
    }

    /**
     * DNS can only be managed here once Cloudflare hosts the zone.
     *
     * @return list<string>
     */
    protected function availableTabs(): array
    {
        return $this->domain->hasCloudflareZone() || $this->domain->hasCloudflareDns() ? ['dns', 'details'] : ['details'];
    }

    public function render()
    {
        return $this->view([
            'tabs' => $this->availableTabs(),
            'recordCount' => $this->domain->dnsRecords()->count(),
        ])->title($this->domain->name);
    }
};
?>

@php
    $expiringWithoutRenewal = $domain->isExpiringWithoutRenewal();
    $daysLeft = $domain->daysUntilExpiry();
    $renewsAtCloudflare = $domain->isRegisteredWithCloudflare();
@endphp

<main
    class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-8 sm:px-6"
    x-data="domainUpdates({ userId: @js($domain->user_id), domain: @js($domain->name), indexUrl: @js(route('domains.index')) })"
>
    <a href="{{ route('domains.index') }}" wire:navigate class="inline-flex w-fit items-center gap-1 text-[13px] text-muted hover:text-fg">
        <x-lucide-chevron-left class="icon" />Domains
    </a>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <h1 class="min-w-0 font-mono text-2xl font-medium tracking-tight break-all">{{ $domain->name }}</h1>
        <div class="flex items-center gap-2">
            <a class="btn" href="https://{{ $domain->name }}" target="_blank" rel="noopener" x-tooltip="@js('Open '.$domain->name.' in a new tab')">
                <x-lucide-arrow-up-right class="icon" />Visit
            </a>
            <button type="button" class="btn" wire:click="editDetails" @disabled($editing)>
                <x-lucide-pencil class="icon" />Edit details
            </button>
        </div>
    </div>

    @if ($expiringWithoutRenewal)
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px]">
            <x-lucide-circle-alert class="icon text-crit" />
            Expires in {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }} and auto‑renew is off.
            <button type="button" wire:click="setAutoRenew(true)" class="underline decoration-line underline-offset-4 hover:decoration-fg">Turn on auto‑renew</button>
        </div>
    @endif

    {{-- At-a-glance facts, always visible above the tabs. --}}
    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-md border border-line bg-line text-[13px] lg:grid-cols-5 [&>div]:flex [&>div]:flex-col [&>div]:gap-1 [&>div]:bg-bg [&>div]:p-3.5 [&>div:last-child]:col-span-2 lg:[&>div:last-child]:col-span-1 [&_dt]:text-[12px] [&_dt]:text-muted">
        <div>
            <dt>Registrar</dt>
            <dd>{{ $domain->registrarLabel() }}</dd>
            <dd class="text-[12px] text-muted">
                {{ $domain->registered_on ? 'Since '.$domain->registered_on->format('M Y') : 'Registration date unknown' }}
            </dd>
        </div>
        <div>
            <dt>Expires</dt>
            <dd @class(['num', 'text-crit' => $expiringWithoutRenewal])>{{ $domain->expires_on?->format('M j, Y') ?? '—' }}</dd>
            @if ($domain->expires_on)
                <dd @class(['text-[12px]', 'text-crit' => $expiringWithoutRenewal, 'text-muted' => ! $expiringWithoutRenewal])>{{ $domain->expires_on->diffForHumans(today(), ['syntax' => \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW]) }}</dd>
            @endif
        </div>
        <div>
            <dt id="auto-renew-label">Auto‑renew</dt>
            <dd class="flex items-center gap-2">
                <button
                    type="button"
                    role="switch"
                    aria-checked="{{ $domain->auto_renew ? 'true' : 'false' }}"
                    aria-labelledby="auto-renew-label"
                    wire:click="setAutoRenew({{ $domain->auto_renew ? 'false' : 'true' }})"
                    wire:loading.attr="disabled"
                    wire:target="setAutoRenew"
                    @class([
                        'relative inline-flex h-5 w-9 shrink-0 items-center rounded-full border transition-colors disabled:cursor-wait disabled:opacity-50',
                        'border-fg bg-fg' => $domain->auto_renew,
                        'border-line bg-subtle' => ! $domain->auto_renew,
                    ])
                >
                    <span @class([
                        'size-3.5 rounded-full shadow-sm transition-transform',
                        'translate-x-[17px] bg-bg' => $domain->auto_renew,
                        'translate-x-[2px] bg-muted' => ! $domain->auto_renew,
                    ])></span>
                </button>
                <span @class(['text-crit' => $expiringWithoutRenewal])>{{ $domain->auto_renew ? 'On' : 'Off' }}</span>
                <span wire:loading wire:target="setAutoRenew" class="text-[12px] text-muted">Saving…</span>
            </dd>
            <dd class="text-[12px] text-muted">
                {{ $renewsAtCloudflare ? 'Changes at Cloudflare' : 'Tracked here · change it at '.$domain->registrarLabel() }}
            </dd>
        </div>
        <div>
            <dt>Renewal</dt>
            <dd class="num font-mono">{{ $domain->formattedRenewalPrice() }}<span class="font-sans text-muted">{{ $domain->renewal_price_cents !== null ? ' /yr' : '' }}</span></dd>
            <dd class="text-[12px] text-muted">
                @if ($domain->renewal_price_cents === null)
                    <button type="button" wire:click="editDetails" class="underline decoration-line underline-offset-4 hover:text-fg hover:decoration-fg">Add a price</button>
                @elseif ($domain->renewal_price_source === Domain::PRICE_FROM_CLOUDFLARE)
                    Cloudflare at‑cost
                @else
                    Set by hand
                @endif
            </dd>
        </div>
        <div>
            <dt>DNS</dt>
            <dd>{{ $domain->nameserver_provider->label() }}</dd>
            <dd class="text-[12px] text-muted">
                @if ($domain->cloudflare_zone_status === 'pending')
                    Waiting for nameservers
                @elseif ($domain->hasCloudflareDns())
                    {{ Str::plural('record', $recordCount, prependCount: true) }}
                @else
                    Not managed here
                @endif
            </dd>
        </div>
    </dl>

    @if ($autoRenewError)
        <p class="text-[13px] text-crit">{{ $autoRenewError }}</p>
    @endif

    <div class="flex gap-5 border-b border-line text-[13.5px]">
        @if (in_array('dns', $tabs, true))
            <button type="button" class="tab" aria-current="{{ $tab === 'dns' ? 'true' : 'false' }}" wire:click="showTab('dns')">
                DNS records <span class="num text-faint">{{ $recordCount }}</span>
            </button>
        @endif
        <button type="button" class="tab" aria-current="{{ $tab === 'details' ? 'true' : 'false' }}" wire:click="showTab('details')">Details</button>
    </div>

    <div>
        @if ($tab === 'dns')
            <livewire:domains.dns-records :domain="$domain" :key="'dns-'.$domain->id" />
        @else
            @php($lockedUntil = $domain->transferLockedUntil())
            <div class="grid items-start gap-12 lg:grid-cols-2">
                <div class="flex flex-col gap-4">
                    @if ($editing)
                        <div x-on:close-domain-editor="$wire.set('editing', false)">
                            <livewire:domains.domain-editor :domain="$domain" :key="'edit-'.$domain->id" />
                        </div>
                    @else
                        <dl class="text-[13px] [&>div]:flex [&>div]:justify-between [&>div]:gap-4 [&>div]:border-t [&>div]:border-line [&>div]:py-2 [&>div:first-child]:border-t-0 [&_dt]:text-muted [&_dd]:num [&_dd]:text-right">
                            <div><dt>Registrar</dt><dd>{{ $domain->registrarLabel() }}</dd></div>
                            <div><dt>Registered</dt><dd>{{ $domain->registered_on?->format('M j, Y') ?? '—' }}</dd></div>
                            <div>
                                <dt>Expires</dt>
                                <dd @class(['text-crit' => $expiringWithoutRenewal])>
                                    {{ $domain->expires_on?->format('M j, Y') ?? '—' }}@if ($expiringWithoutRenewal) · {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }}@endif
                                </dd>
                            </div>
                            <div><dt>Auto‑renew</dt><dd>{{ $domain->auto_renew ? 'On' : 'Off' }}</dd></div>
                            <div>
                                <dt>Renewal</dt>
                                <dd>
                                    <span class="font-mono">{{ $domain->formattedRenewalPrice() }}</span>
                                    @if ($domain->renewal_price_source === Domain::PRICE_FROM_CLOUDFLARE)
                                        <span class="text-muted">· Cloudflare at-cost</span>
                                    @elseif ($domain->renewal_price_source === Domain::PRICE_SET_MANUALLY && $domain->isRegisteredWithCloudflare())
                                        <span class="text-muted">· set by hand</span>
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt>Nameservers</dt>
                                <dd>
                                    @if ($domain->nameservers)
                                        <span class="font-mono text-[12.5px]">{!! collect($domain->nameservers)->map(fn ($nameserver) => e($nameserver))->implode('<br>') !!}</span>
                                    @else
                                        {{ $domain->nameserver_provider->label() }}
                                    @endif
                                </dd>
                            </div>
                            @if ($domain->note)
                                <div><dt>Note</dt><dd>{{ $domain->note }}</dd></div>
                            @endif
                            @if ($domain->synced_at)
                                <div><dt>Last synced</dt><dd class="text-muted">{{ $domain->synced_at->diffForHumans() }}</dd></div>
                            @endif
                        </dl>
                    @endif

                    @if ($domain->cloudflare_zone_status === 'pending')
                        <p class="text-[12.5px] text-muted">Cloudflare is waiting for this domain’s nameservers to change to the ones above.</p>
                    @elseif (! $domain->hasCloudflareDns())
                        <p class="text-[12.5px] text-muted">DNS for this domain is hosted at {{ $domain->nameserver_provider->label() }}. Point the nameservers to Cloudflare to manage records here.</p>
                    @endif
                </div>

                @if ($domain->isMigratingToCloudflare())
                    @php($completed = $domain->completedTransferSteps())
                    <div class="flex flex-col gap-3">
                        <div class="flex justify-between text-[13px]">
                            <span class="font-medium">Move to Cloudflare</span>
                            <span class="num text-muted">{{ $completed }} of {{ count(Domain::TRANSFER_STEPS) }}</span>
                        </div>
                        <ol class="flex flex-col text-[13px]">
                            @foreach (Domain::TRANSFER_STEPS as $index => $step)
                                <li @class(['flex items-center gap-3 py-1.5', 'text-faint' => $index < $completed, 'text-muted' => $index > $completed])>
                                    <span class="flex w-4 justify-center font-mono text-[12px]">
                                        @if ($index < $completed)
                                            <x-lucide-check class="icon" />
                                        @else
                                            {{ $index + 1 }}
                                        @endif
                                    </span>
                                    <span class="flex-1">{{ $step }}</span>
                                </li>
                            @endforeach
                        </ol>
                        @if ($lockedUntil)
                            <p class="text-[12.5px] text-muted">Registered {{ $domain->registered_on->format('M j, Y') }}. Transfers open {{ $lockedUntil->format('M j') }} (60‑day ICANN lock).</p>
                        @endif
                        @unless ($domain->hasCloudflareDns())
                            <p class="text-[12.5px] text-muted">Cloudflare requires its own nameservers before it accepts a transfer.</p>
                        @endunless
                    </div>
                @endif
            </div>
        @endif
    </div>
</main>
