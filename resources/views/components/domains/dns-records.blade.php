<?php

use App\Events\PortfolioUpdated;
use App\Livewire\Forms\DnsRecordForm;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Services\Cloudflare\CloudflareException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public Domain $domain;

    public string $filter = '';

    /**
     * Which row is open in the editor: null, "new", or a record id.
     */
    #[Locked]
    public int|string|null $editing = null;

    public bool $confirmingDelete = false;

    public DnsRecordForm $form;

    /**
     * @return Collection<int, DnsRecord>
     */
    #[Computed]
    public function records(): Collection
    {
        $term = strtolower(trim($this->filter));

        return $this->domain->dnsRecords()->get()
            ->sortBy([
                fn (DnsRecord $a, DnsRecord $b) => $a->type->sortOrder() <=> $b->type->sortOrder(),
                fn (DnsRecord $a, DnsRecord $b) => strcmp($a->name, $b->name),
                fn (DnsRecord $a, DnsRecord $b) => ($a->priority ?? 0) <=> ($b->priority ?? 0),
            ])
            ->when($term !== '', fn (Collection $records) => $records->filter(
                fn (DnsRecord $record) => str_contains(strtolower("{$record->type->value} {$record->name} {$record->content}"), $term)
            ))
            ->values();
    }

    /**
     * Records changed in another tab or device.
     */
    #[On('portfolio-refreshed')]
    public function refreshRecords(): void
    {
        unset($this->records);
    }

    public function create(): void
    {
        $this->authorize('update', $this->domain);
        $this->form->reset();
        $this->form->resetErrorBag();
        $this->editing = 'new';
        $this->confirmingDelete = false;
    }

    public function edit(int $recordId): void
    {
        $this->authorize('update', $this->domain);
        $record = $this->domain->dnsRecords()->findOrFail($recordId);

        if (! $record->type->isEditable()) {
            return;
        }

        $this->form->edit($record);
        $this->editing = $recordId;
        $this->confirmingDelete = false;
    }

    public function cancel(): void
    {
        $this->form->reset();
        $this->form->resetErrorBag();
        $this->editing = null;
        $this->confirmingDelete = false;
    }

    public function save(): void
    {
        $this->authorize('update', $this->domain);
        $isNew = $this->form->record === null;
        $record = $this->form->save($this->domain);

        $this->cancel();
        PortfolioUpdated::dispatch($this->domain->user_id, $this->domain->name);
        $this->dispatch('dns-records-changed');
        $this->dispatch('toast', message: ($isNew ? 'Added' : 'Updated')." {$record->type->value} record {$record->name}");
    }

    public function delete(): void
    {
        $record = $this->form->record;

        if (! $record || $record->domain_id !== $this->domain->id) {
            return;
        }

        $this->authorize('update', $this->domain);

        if ($this->domain->hasCloudflareZone() && $record->cloudflare_id) {
            try {
                $this->domain->cloudflareClient()->deleteDnsRecord($this->domain->cloudflare_zone_id, $record->cloudflare_id);
            } catch (CloudflareException $exception) {
                // Already gone in Cloudflare counts as deleted.
                if ($exception->status !== 404) {
                    $this->addError('form.cloudflare', 'Cloudflare: '.$exception->getMessage());

                    return;
                }
            }
        }

        $record->delete();

        $this->cancel();
        PortfolioUpdated::dispatch($this->domain->user_id, $this->domain->name);
        $this->dispatch('dns-records-changed');
        $this->dispatch('toast', message: "Deleted {$record->type->value} record {$record->name}");
    }
};
?>

<div class="flex flex-col gap-4" x-on:keydown.escape.window="$wire.editing !== null && $wire.cancel()">
    <div class="flex items-center gap-3">
        <label class="relative flex-1">
            <span class="sr-only">Filter records</span>
            <x-lucide-search class="icon absolute top-1/2 left-0 -translate-y-1/2 text-faint" />
            <input type="search" wire:model.live.debounce.150ms="filter" data-search placeholder="Filter by name, type or content" class="w-full bg-transparent py-1 pl-6 text-[13px] placeholder:text-faint focus:outline-none">
        </label>
        <button type="button" class="btn btn-solid" wire:click="create" @disabled($editing === 'new')>
            <x-lucide-plus class="icon" />Add record
        </button>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-left text-[13px]">
            <thead class="text-[12px] text-muted">
                <tr class="[&>th]:py-2 [&>th]:pr-3 [&>th]:font-normal">
                    <th class="w-16">Type</th>
                    <th>Name</th>
                    <th>Content</th>
                    <th class="w-16">TTL</th>
                    <th class="w-24">Proxy</th>
                </tr>
            </thead>
            <tbody>
                @if ($editing === 'new')
                    <x-domains.dns-record-form-row :form="$form" :domain="$domain" wire:key="form-new" />
                @endif

                @forelse ($this->records as $record)
                    @if ($editing === $record->id)
                        <x-domains.dns-record-form-row :form="$form" :domain="$domain" :confirming-delete="$confirmingDelete" wire:key="form-{{ $record->id }}" />
                    @elseif ($record->type->isEditable())
                        <tr
                            wire:key="record-{{ $record->id }}"
                            wire:click="edit({{ $record->id }})"
                            x-on:keydown.enter="$wire.edit({{ $record->id }})"
                            tabindex="0"
                            class="cursor-pointer border-t border-line align-top hover:bg-subtle [&>td]:py-2.5 [&>td]:pr-3"
                        >
                            <x-domains.dns-record-cells :record="$record" />
                        </tr>
                    @else
                        <tr
                            wire:key="record-{{ $record->id }}"
                            x-tooltip.top="@js($record->type->value.' records are edited in the Cloudflare dashboard')"
                            class="border-t border-line align-top [&>td]:py-2.5 [&>td]:pr-3"
                        >
                            <x-domains.dns-record-cells :record="$record" />
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="5" class="border-t border-line py-10 text-center text-muted">
                            {{ $filter !== '' ? 'No records match “'.$filter.'”.' : 'No DNS records yet. Add one to get started.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="text-[12px] text-faint">
        @if ($domain->hasCloudflareZone())
            Changes apply immediately through the Cloudflare API. Proxied records always use Auto TTL.
        @else
            This domain isn’t linked to a Cloudflare zone, so changes are only saved here.
        @endif
    </p>
</div>
