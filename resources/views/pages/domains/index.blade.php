<?php

use App\Enums\Provider;
use App\Models\CloudflareConnection;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Domains')] class extends Component
{
    #[Url(except: 'all')]
    public string $registrar = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'expires')]
    public string $sort = 'expires';

    #[Url(as: 'dir', except: 'asc')]
    public string $direction = 'asc';

    #[Url(except: '')]
    public string $expires = '';

    #[Url(except: '')]
    public string $renew = '';

    #[Url(as: 'dns', except: '')]
    public string $nameservers = '';

    #[Url(except: '')]
    public string $price = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $adding = false;

    /**
     * True while this page is following a background sync, so it can announce when it finishes.
     */
    public bool $watchingSync = false;

    /**
     * Used in the broadcast channel name for live updates from other tabs and devices.
     */
    #[Locked]
    public int $userId;

    /**
     * View settings remembered between visits (search is deliberately left out).
     */
    protected const array REMEMBERED = ['registrar', 'sort', 'direction', 'expires', 'renew', 'nameservers', 'price', 'status'];

    protected const string SESSION_KEY = 'domains.view';

    public function mount(): void
    {
        $this->userId = auth()->id();
        $this->watchingSync = (bool) $this->connection?->isSyncing();
        $this->restoreView();
    }

    /**
     * Remember the current sort and filters after every request.
     */
    public function dehydrate(): void
    {
        session()->put(self::SESSION_KEY, collect(self::REMEMBERED)->mapWithKeys(fn (string $property) => [$property => $this->{$property}])->all());
    }

    /**
     * Arriving at a plain /domains link brings back the last sort and filters. A link that carries its own
     * filters in the URL wins, so shared or bookmarked views open exactly as linked.
     */
    protected function restoreView(): void
    {
        $defaults = ['registrar' => 'all', 'sort' => 'expires', 'direction' => 'asc', 'search' => ''];
        $atDefaults = collect([...self::REMEMBERED, 'search'])->every(fn (string $property) => $this->{$property} === ($defaults[$property] ?? ''));
        $saved = session(self::SESSION_KEY);

        if (! $atDefaults || ! is_array($saved)) {
            return;
        }

        $saved = array_filter($saved, 'is_string');

        // Go through the same validation as clicks in the UI, so a stale or tampered session can't set junk.
        $this->sortBy($saved['sort'] ?? 'expires', $saved['direction'] ?? 'asc');

        foreach (array_diff(self::REMEMBERED, ['sort', 'direction']) as $property) {
            $this->filter($property, $saved[$property] ?? '');
        }
    }

    /**
     * Column header menus: how each column sorts, and the filters it offers.
     * Filter groups are keyed by the public property they set.
     *
     * @return array<string, array{label: string, align?: string, sort?: array{0: string, 1: string}, filters: array<string, array{label: string, options: array<string, string>}>}>
     */
    #[Computed]
    public function columns(): array
    {
        return [
            'name' => [
                'label' => 'Domain',
                'sort' => ['A → Z', 'Z → A'],
                'filters' => [],
            ],
            'registrar' => [
                'label' => 'Registrar',
                'sort' => ['A → Z', 'Z → A'],
                'filters' => [
                    'registrar' => ['label' => 'Registrar', 'options' => collect(Provider::cases())->mapWithKeys(fn (Provider $provider) => [$provider->value => $provider->label()])->all()],
                    'nameservers' => ['label' => 'Nameservers', 'options' => ['cloudflare' => 'At Cloudflare', 'elsewhere' => 'Elsewhere']],
                ],
            ],
            'expires' => [
                'label' => 'Expires',
                'sort' => ['Soonest first', 'Latest first'],
                'filters' => [
                    'expires' => ['label' => 'Expires', 'options' => ['30' => 'Within 30 days', '90' => 'Within 90 days', '365' => 'Within a year', 'unknown' => 'Unknown']],
                ],
            ],
            'auto_renew' => [
                'label' => 'Auto‑renew',
                'sort' => ['Off first', 'On first'],
                'filters' => [
                    'renew' => ['label' => 'Auto‑renew', 'options' => ['on' => 'On', 'off' => 'Off']],
                ],
            ],
            'price' => [
                'label' => 'Renewal',
                'align' => 'right',
                'sort' => ['Lowest first', 'Highest first'],
                'filters' => [
                    'price' => ['label' => 'Price', 'options' => ['known' => 'Has a price', 'unknown' => 'No price yet']],
                ],
            ],
            'status' => [
                'label' => 'Status',
                'align' => 'right',
                'filters' => [
                    'status' => ['label' => 'Status', 'options' => [
                        'attention' => 'Expiring without auto‑renew',
                        'ready' => 'Ready to transfer',
                        'transferring' => 'Transfer pending',
                        'locked' => 'Transfer locked',
                        'waiting' => 'Waiting for nameservers',
                    ]],
                ],
            ],
        ];
    }

    #[Computed]
    public function connection(): ?CloudflareConnection
    {
        return auth()->user()->cloudflareConnection()->first();
    }

    /**
     * Every domain, for the summary line, tab counts and alerts.
     *
     * @return Collection<int, Domain>
     */
    #[Computed]
    public function portfolio(): Collection
    {
        return auth()->user()->domains()->orderBy('expires_on')->get();
    }

    /**
     * The portfolio after search, filters and sorting. Portfolios are small, so this happens in memory,
     * which keeps derived states (like "ready to transfer") filterable.
     *
     * @return Collection<int, Domain>
     */
    #[Computed]
    public function domains(): Collection
    {
        $term = Str::lower(trim($this->search));

        return $this->portfolio
            ->filter(fn (Domain $domain) => $this->matchesSearch($domain, $term) && $this->matchesFilters($domain))
            ->sort(fn (Domain $a, Domain $b) => $this->compare($a, $b))
            ->values();
    }

    /**
     * For every filter option, how many domains would match if it were chosen, given the search and the other filters.
     *
     * @return array<string, array<string, int>>
     */
    #[Computed]
    public function filterCounts(): array
    {
        $term = Str::lower(trim($this->search));
        $searched = $this->portfolio->filter(fn (Domain $domain) => $this->matchesSearch($domain, $term));

        return collect($this->columns)
            ->flatMap(fn (array $column) => $column['filters'])
            ->map(fn (array $group, string $property) => collect(['' => 'Any'] + $group['options'])
                ->map(fn (string $label, string|int $value) => $searched
                    ->filter(fn (Domain $domain) => $this->matchesFilters($domain, [$property => (string) $value]))
                    ->count())
                ->all())
            ->all();
    }

    /**
     * Active filters as [property => [group label, option label]], for the chips under the toolbar.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    #[Computed]
    public function activeFilters(): array
    {
        return collect($this->columns)
            ->flatMap(fn (array $column) => $column['filters'])
            ->filter(fn (array $group, string $property) => isset($group['options'][$this->{$property}]))
            ->map(fn (array $group, string $property) => [$group['label'], $group['options'][$this->{$property}]])
            ->all();
    }

    public function sortBy(string $column, string $direction): void
    {
        if (isset($this->columns[$column]['sort'])) {
            $this->sort = $column;
            $this->direction = $direction === 'desc' ? 'desc' : 'asc';
        }
    }

    /**
     * Set one filter. An empty or unknown value clears it.
     */
    public function filter(string $property, string $value): void
    {
        $group = collect($this->columns)->flatMap(fn (array $column) => $column['filters'])->get($property);

        if ($group) {
            $this->{$property} = isset($group['options'][$value]) ? $value : ($property === 'registrar' ? 'all' : '');
        }
    }

    public function clearFilters(): void
    {
        $this->reset('registrar', 'search', 'expires', 'renew', 'nameservers', 'price', 'status');
    }

    public function sync(): void
    {
        if (! $this->connection) {
            $this->redirectRoute('settings', navigate: true);

            return;
        }

        $this->connection->queueSync();
        $this->watchingSync = true;
        unset($this->connection);
    }

    /**
     * Another tab or device changed something (or a sync started/finished): re-read everything.
     */
    #[On('echo-private:App.Models.User.{userId},.portfolio.updated')]
    public function portfolioUpdated(): void
    {
        unset($this->connection, $this->portfolio, $this->domains, $this->filterCounts);

        if ($this->connection?->isSyncing()) {
            $this->watchingSync = true;
        }
    }

    /**
     * Stop waiting on a sync no worker has picked up.
     */
    public function cancelSync(): void
    {
        $this->connection?->cancelQueuedSync();
        $this->watchingSync = false;
        unset($this->connection);

        $this->dispatch('toast', message: 'Sync cancelled');
    }

    /**
     * Polled while a sync runs: refreshes the list (so a first import fills in live) and announces the result.
     */
    public function checkSync(): void
    {
        unset($this->connection, $this->portfolio, $this->domains, $this->filterCounts);

        if ($this->watchingSync && ! $this->connection?->isSyncing()) {
            $this->watchingSync = false;
            $this->dispatch('toast', message: $this->connection?->last_sync_error
                ? 'Sync finished with warnings'
                : 'Synced '.Str::plural('domain', $this->portfolio->count(), prependCount: true));
        }
    }

    protected function matchesSearch(Domain $domain, string $term): bool
    {
        if ($term === '') {
            return true;
        }

        $haystack = Str::lower(implode(' ', array_filter([
            $domain->name,
            $domain->registrarLabel(),
            $domain->note,
            $domain->statusNote(),
            implode(' ', $domain->nameservers ?? []),
        ])));

        return str_contains($haystack, $term);
    }

    /**
     * @param  array<string, string>  $overrides  filter values to use instead of the current ones (for counting options)
     */
    protected function matchesFilters(Domain $domain, array $overrides = []): bool
    {
        $value = fn (string $property) => $overrides[$property] ?? $this->{$property};
        $days = $domain->daysUntilExpiry();
        $registrar = Provider::tryFrom($value('registrar'));
        $expires = $value('expires');

        return match (true) {
            $registrar !== null && $domain->registrar !== $registrar => false,
            $value('nameservers') === 'cloudflare' && ! $domain->hasCloudflareDns() => false,
            $value('nameservers') === 'elsewhere' && $domain->hasCloudflareDns() => false,
            in_array($expires, ['30', '90', '365'], true) && ($days === null || $days > (int) $expires) => false,
            $expires === 'unknown' && $domain->expires_on !== null => false,
            $value('renew') === 'on' && ! $domain->auto_renew => false,
            $value('renew') === 'off' && ($domain->auto_renew || $domain->expires_on === null) => false,
            $value('price') === 'known' && $domain->renewal_price_cents === null => false,
            $value('price') === 'unknown' && $domain->renewal_price_cents !== null => false,
            $value('status') !== '' && ! $this->hasStatus($domain, $value('status')) => false,
            default => true,
        };
    }

    protected function hasStatus(Domain $domain, string $status): bool
    {
        return match ($status) {
            'attention' => $domain->isExpiringWithoutRenewal(),
            'ready' => $domain->registrar === Provider::Squarespace && $domain->hasCloudflareDns(),
            'transferring' => $domain->registration_status === 'transfer_pending' || $domain->note === 'Transfer pending',
            'locked' => $domain->transferLockedUntil() !== null,
            'waiting' => $domain->cloudflare_zone_status === 'pending',
            default => true,
        };
    }

    /**
     * Sort by the chosen column; unknown values always sink to the bottom, and ties fall back to name.
     */
    protected function compare(Domain $a, Domain $b): int
    {
        $value = fn (Domain $domain) => match ($this->sort) {
            'name' => $domain->name,
            'registrar' => Str::lower($domain->registrarLabel()),
            'auto_renew' => $domain->expires_on ? (int) $domain->auto_renew : null,
            'price' => $domain->renewal_price_cents,
            default => $domain->expires_on?->getTimestamp(),
        };

        [$left, $right] = [$value($a), $value($b)];

        if ($left === null || $right === null) {
            return ($left === null) <=> ($right === null) ?: strcmp($a->name, $b->name);
        }

        $order = $left <=> $right;

        return ($this->direction === 'desc' ? -$order : $order) ?: strcmp($a->name, $b->name);
    }
};
?>

@php
    $portfolio = $this->portfolio;
    $countFor = fn (Provider $provider) => $portfolio->where('registrar', $provider)->count();
    $lastSyncedAt = $this->connection?->last_synced_at;
    $registrarCounts = $this->filterCounts['registrar'];
    $tabs = ['all' => ['All', $registrarCounts['']]] + collect(Provider::cases())
        ->mapWithKeys(fn (Provider $provider) => [$provider->value => [$provider->label(), $registrarCounts[$provider->value]]])
        ->filter(fn (array $tab, string $key) => $countFor(Provider::from($key)) > 0 || $key === $registrar)
        ->all();
    $summary = array_filter([
        Str::plural('domain', $portfolio->count(), prependCount: true),
        implode(', ', array_filter([
            $countFor(Provider::Cloudflare) ? $countFor(Provider::Cloudflare).' on Cloudflare' : null,
            $countFor(Provider::Squarespace) ? $countFor(Provider::Squarespace).' left on Squarespace' : null,
            $countFor(Provider::Other) ? $countFor(Provider::Other).' elsewhere' : null,
        ])),
        $portfolio->whereNotNull('renewal_price_cents')->isNotEmpty() ? Domain::formatCents($portfolio->sum('renewal_price_cents')).'/yr in renewals' : null,
    ]);
    $activeFilters = $this->activeFilters;
    $isFiltered = $activeFilters !== [] || $search !== '';
    $connection = $this->connection;
    $isSyncing = (bool) $connection?->isSyncing();
@endphp

<main class="mx-auto flex max-w-6xl flex-col gap-8 px-4 py-10 sm:px-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">Domains</h1>
            <p class="num text-muted">{{ implode(' · ', $summary) }}</p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" class="btn" wire:click="sync" @disabled($isSyncing) x-tooltip="'Pull the latest from Cloudflare'">
                <x-lucide-refresh-cw @class(['icon', 'animate-spin' => $isSyncing]) />{{ $isSyncing ? 'Syncing…' : 'Sync' }}
            </button>
            <button type="button" class="btn btn-solid" wire:click="$set('adding', true)" @disabled($adding)>
                <x-lucide-plus class="icon" />Add domain
            </button>
        </div>
    </div>

    @if ($adding)
        <div x-on:close-domain-editor="$wire.set('adding', false)">
            <livewire:domains.domain-editor wire:key="add-domain" />
        </div>
    @endif

    @if ($isSyncing)
        <div wire:poll.1s="checkSync">
            <x-sync-progress :connection="$connection" :first-import="$connection->last_synced_at === null" />
        </div>
    @elseif ($watchingSync)
        {{-- The sync finished between polls; one more check announces it. --}}
        <div wire:poll.1s="checkSync"></div>
    @endif

    @if ($reminder = $this->connection?->rotationReminder())
        <div class="flex items-start gap-3 text-[13px]">
            <x-lucide-circle-alert @class(['icon mt-0.5', 'text-crit' => $this->connection->tokenHasExpired(), 'text-warn' => ! $this->connection->tokenHasExpired()]) />
            <span>{{ $reminder }} <a href="{{ route('settings') }}" wire:navigate class="underline decoration-line underline-offset-4 hover:decoration-fg">Replace token</a></span>
        </div>
    @endif

    @if ($this->connection?->last_sync_error)
        <div class="flex items-start gap-3 text-[13px]">
            <x-lucide-circle-alert class="icon mt-0.5 text-warn" />
            <span>{{ $this->connection->last_sync_error }} <a href="{{ route('settings') }}" wire:navigate class="underline decoration-line underline-offset-4 hover:decoration-fg">Settings</a></span>
        </div>
    @endif

    @php($needsAttention = $portfolio->filter->isExpiringWithoutRenewal())
    @if ($needsAttention->isNotEmpty())
        <div class="flex flex-col gap-1.5">
            @foreach ($needsAttention as $domain)
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px]" wire:key="alert-{{ $domain->id }}">
                    <x-lucide-circle-alert class="icon text-crit" />
                    <span><span class="font-mono">{{ $domain->name }}</span> expires {{ $domain->expires_on->format('M j, Y') }} and auto‑renew is off.</span>
                    <a href="{{ route('domains.show', $domain) }}" wire:navigate class="underline decoration-line underline-offset-4 hover:decoration-fg">Review</a>
                </div>
            @endforeach
        </div>
    @endif

    <section class="flex flex-col gap-3" aria-label="Domains">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-3 border-b border-line">
            <div class="flex gap-5 text-[13.5px]">
                @foreach ($tabs as $key => [$label, $count])
                    <button type="button" class="tab" aria-current="{{ $registrar === $key ? 'true' : 'false' }}" wire:click="filter('registrar', '{{ $key }}')">
                        {{ $label }} <span class="num text-faint">{{ $count }}</span>
                    </button>
                @endforeach
            </div>
            <label class="relative ml-auto w-full pb-2 sm:w-64">
                <span class="sr-only">Search domains</span>
                <x-lucide-search class="icon absolute top-[calc(50%-4px)] left-0 -translate-y-1/2 text-faint" />
                <input type="search" wire:model.live.debounce.150ms="search" data-search placeholder="Search names, notes, status…" class="w-full bg-transparent py-1 pl-6 text-[13px] placeholder:text-faint focus:outline-none">
            </label>
        </div>

        @if ($isFiltered)
            <div class="flex flex-wrap items-center gap-2 text-[12.5px]">
                <span class="num mr-1 text-muted"><span class="font-medium text-fg">{{ $this->domains->count() }}</span> of {{ $portfolio->count() }} {{ Str::plural('domain', $portfolio->count()) }} match</span>
                @foreach ($activeFilters as $property => [$group, $option])
                    <button type="button" wire:key="chip-{{ $property }}" wire:click="filter('{{ $property }}', '')" class="inline-flex items-center gap-1.5 rounded-full border border-line px-2.5 py-0.5 hover:bg-subtle" aria-label="Remove filter {{ $group }}: {{ $option }}">
                        <span class="text-muted">{{ $group }}:</span> {{ $option }}
                        <x-lucide-x class="size-3 text-faint" />
                    </button>
                @endforeach
                <button type="button" wire:click="clearFilters" class="text-muted underline decoration-line underline-offset-4 hover:text-fg hover:decoration-fg">Clear {{ $activeFilters === [] ? 'search' : 'all' }}</button>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full min-w-[680px] text-left">
                <thead class="text-[12px] text-muted">
                    <tr class="[&>th]:py-2 [&>th]:pr-4 [&>th]:font-normal">
                        @foreach ($this->columns as $key => $column)
                            <x-domains.column-header
                                :key="$key"
                                :column="$column"
                                :sort="$sort"
                                :direction="$direction"
                                :values="collect($column['filters'])->keys()->mapWithKeys(fn ($property) => [$property => $this->{$property}])->all()"
                                :counts="$this->filterCounts"
                            />
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->domains as $domain)
                        @php($url = route('domains.show', $domain))
                        <tr
                            wire:key="domain-{{ $domain->id }}"
                            class="cursor-pointer border-t border-line hover:bg-subtle [&>td]:py-3 [&>td]:pr-4"
                            x-on:click="$event.target.closest('a') || Livewire.navigate(@js($url))"
                        >
                            <td class="font-mono text-[13.5px]">
                                <a href="{{ $url }}" wire:navigate class="underline-offset-4 hover:underline">{{ $domain->name }}</a>
                            </td>
                            <td>
                                {{ $domain->registrarLabel() }}
                                @if ($domain->hasSplitProviders())
                                    <div class="text-[12px] text-muted">Nameservers: {{ $domain->nameserver_provider->label() }}</div>
                                @endif
                            </td>
                            <td class="num">{{ $domain->expires_on?->format('M j, Y') ?? '—' }}</td>
                            <td @class(['text-crit' => $domain->isExpiringWithoutRenewal(), 'text-muted' => ! $domain->auto_renew && ! $domain->isExpiringWithoutRenewal()])>
                                {{ $domain->expires_on ? ($domain->auto_renew ? 'On' : 'Off') : '—' }}
                            </td>
                            <td class="num text-right font-mono text-[13px]">{{ $domain->formattedRenewalPrice() }}</td>
                            <td class="text-[13px] whitespace-nowrap text-muted">
                                <div class="flex items-center justify-end gap-3">
                                    @if ($domain->isExpiringWithoutRenewal())
                                        <span class="text-crit">Expires in {{ $domain->daysUntilExpiry() }} {{ Str::plural('day', $domain->daysUntilExpiry()) }}</span>
                                    @else
                                        {{ $domain->statusNote() }}
                                    @endif
                                    <x-lucide-chevron-right class="icon text-faint" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="border-t border-line py-12 text-center text-muted">
                                @if ($search !== '')
                                    No domains match “{{ $search }}”.
                                @elseif ($isFiltered)
                                    No domains match these filters. <button type="button" wire:click="clearFilters" class="text-fg underline decoration-line underline-offset-4 hover:decoration-fg">Clear filters</button>
                                @elseif ($portfolio->isEmpty() && ! $this->connection)
                                    No domains yet. <a href="{{ route('settings') }}" wire:navigate class="text-fg underline decoration-line underline-offset-4 hover:decoration-fg">Connect Cloudflare</a> to import them, or add one by hand.
                                @else
                                    No domains here yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="num text-[12px] text-faint">
            {{ $this->domains->count() }} of {{ $portfolio->count() }} domains
            @if ($lastSyncedAt)
                · last synced {{ $lastSyncedAt->diffForHumans() }}
            @endif
        </p>
    </section>
</main>
