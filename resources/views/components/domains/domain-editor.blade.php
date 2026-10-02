<?php

use App\Enums\Provider;
use App\Events\PortfolioUpdated;
use App\Livewire\Forms\DomainForm;
use App\Models\Domain;
use App\Services\Rdap\RdapClient;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    /**
     * The domain being edited, or null when adding one.
     */
    #[Locked]
    public ?Domain $domain = null;

    public DomainForm $form;

    public bool $confirmingDelete = false;

    public function mount(): void
    {
        if ($this->domain) {
            $this->authorize('update', $this->domain);
            $this->form->edit($this->domain);
        }
    }

    public function save(RdapClient $rdap): void
    {
        if ($this->domain) {
            $this->authorize('update', $this->domain);
        }

        $domain = $this->form->save(auth()->user(), $rdap);
        PortfolioUpdated::dispatch($domain->user_id, $domain->name);

        if ($this->domain) {
            $this->dispatch('domain-updated');
            $this->dispatch('toast', message: 'Saved '.$domain->name);

            return;
        }

        $this->redirectRoute('domains.show', $domain, navigate: true);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->domain);
        $this->domain->delete();
        PortfolioUpdated::dispatch($this->domain->user_id, $this->domain->name, removed: true);

        $this->redirectRoute('domains.index', navigate: true);
    }
};
?>

@php($isNew = $domain === null)

<form wire:submit="save" class="flex flex-col gap-4 rounded-md border border-line bg-subtle p-4" x-on:keydown.escape="$dispatch('close-domain-editor')">
    <div class="text-[13px] font-medium">{{ $isNew ? 'Add a domain' : 'Edit details' }}</div>

    @if ($isNew)
        <x-text-field label="Domain" name="form.name" wire:model="form.name" placeholder="example.com" autocomplete="off" spellcheck="false" class="font-mono" x-init="$el.focus()"
            hint="Domains in Cloudflare are added by Sync. Add domains held elsewhere, like Squarespace, here." />
    @endif

    @if ($form->managesRegistration())
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <label class="flex flex-col gap-1.5 text-[12px] text-muted">Registrar
                <x-select wire:model="form.registrar">
                    @foreach (Provider::cases() as $provider)
                        <option value="{{ $provider->value }}">{{ $provider->label() }}</option>
                    @endforeach
                </x-select>
            </label>
            <x-text-field label="Registered" name="form.registered_on" type="date" wire:model="form.registered_on" :no-autofill="true" />
            <x-text-field label="Expires" name="form.expires_on" type="date" wire:model="form.expires_on" :no-autofill="true" />
        </div>
        @if ($isNew)
            <p class="-mt-2 text-[12px] text-faint">Leave dates blank to look them up from the public registry.</p>
        @endif
    @endif

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-[160px_1fr]">
        <x-text-field label="Renewal price (USD/yr)" name="form.renewal_price" wire:model="form.renewal_price" inputmode="decimal" placeholder="20.00" class="num font-mono"
            :hint="$domain?->isRegisteredWithCloudflare() ? 'Synced from Cloudflare. Clear it to go back to Cloudflare’s price.' : null" />
        <x-text-field label="Note" name="form.note" wire:model="form.note" placeholder="Optional" />
    </div>

    @if ($form->managesRegistration())
        <label class="flex items-center gap-2 text-[13px]">
            <x-checkbox wire:model="form.auto_renew" />
            Auto‑renew is on at the registrar
        </label>
    @endif

    <div class="flex flex-wrap items-center gap-2 pt-1">
        @unless ($isNew)
            @if ($confirmingDelete)
                <span class="text-[13px]">Remove {{ $domain->name }} from your list?</span>
                <button type="button" class="btn text-crit" wire:click="delete">Remove</button>
                <button type="button" class="btn" wire:click="$set('confirmingDelete', false)">Keep</button>
            @else
                <button type="button" class="text-[13px] text-muted hover:text-crit" wire:click="$set('confirmingDelete', true)">Remove domain</button>
            @endif
        @endunless
        <div class="ml-auto flex gap-2">
            <button type="button" class="btn" x-on:click="$dispatch('close-domain-editor')">Cancel</button>
            <button type="submit" class="btn btn-solid" wire:loading.attr="disabled" wire:target="save">{{ $isNew ? 'Add domain' : 'Save' }}</button>
        </div>
    </div>
</form>
