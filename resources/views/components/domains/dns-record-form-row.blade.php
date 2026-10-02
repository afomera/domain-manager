@props(['form', 'domain', 'confirmingDelete' => false])

@php
    $isNew = $form->record === null;
    $type = $form->recordType();
    $proxyable = $type->isProxyable();
@endphp

<tr {{ $attributes->merge(['class' => 'border-t border-line bg-subtle']) }}>
    <td colspan="5" class="p-0">
        <form wire:submit="save" class="flex flex-col gap-4 p-4" novalidate>
            <div class="text-[13px] font-medium">{{ $isNew ? 'New record' : "Edit {$type->value} record" }}</div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-[120px_1fr]">
                <label class="flex flex-col gap-1.5 text-[12px] text-muted">Type
                    <x-select wire:model.live="form.type" class="font-mono">
                        @foreach (App\Enums\DnsRecordType::editable() as $option)
                            <option value="{{ $option->value }}">{{ $option->value }}</option>
                        @endforeach
                    </x-select>
                </label>
                <label class="flex flex-col gap-1.5 text-[12px] text-muted">Name
                    <div class="flex items-center rounded-md border border-line bg-bg focus-within:border-fg">
                        <input
                            wire:model="form.name"
                            placeholder="@ for root"
                            autocomplete="off"
                            spellcheck="false"
                            @if ($isNew) x-init="$el.focus()" @endif
                            class="min-w-0 flex-1 bg-transparent px-2.5 py-1.5 font-mono text-[13px] text-fg focus:outline-none"
                        >
                        <span class="truncate pr-2.5 font-mono text-[12px] text-faint">.{{ $domain->name }}</span>
                    </div>
                </label>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_96px]">
                <label class="flex flex-col gap-1.5 text-[12px] text-muted">{{ $type->contentLabel() }}
                    <input
                        wire:model="form.content"
                        autocomplete="off"
                        spellcheck="false"
                        @unless ($isNew) x-init="$el.focus()" @endunless
                        class="field font-mono"
                    >
                </label>
                @if ($type === App\Enums\DnsRecordType::MX)
                    <label class="flex flex-col gap-1.5 text-[12px] text-muted">Priority
                        <input wire:model="form.priority" type="number" min="0" max="65535" class="field num font-mono">
                    </label>
                @endif
            </div>

            <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
                <label class="flex flex-col gap-1.5 text-[12px] text-muted">TTL
                    <x-select wire:model="form.ttl" class="w-28" :disabled="$proxyable && $form->proxied">
                        @foreach ($form->ttlOptions() as $seconds => $label)
                            <option value="{{ $seconds }}">{{ $label }}</option>
                        @endforeach
                    </x-select>
                </label>
                <label @class(['flex items-center gap-2 pb-2 text-[13px]', 'opacity-40' => ! $proxyable])>
                    <x-checkbox wire:model.live="form.proxied" :disabled="! $proxyable" />
                    Proxy through Cloudflare
                </label>
            </div>

            @if ($errors->isNotEmpty())
                <p class="text-[12.5px] text-crit">{{ $errors->first() }}</p>
            @endif

            <div class="flex flex-wrap items-center gap-2 pt-1">
                @unless ($isNew)
                    @if ($confirmingDelete)
                        <span class="text-[13px]">Delete this record?</span>
                        <button type="button" class="btn text-crit" wire:click="delete">Delete</button>
                        <button type="button" class="btn" wire:click="$set('confirmingDelete', false)">Keep</button>
                    @else
                        <button type="button" class="text-[13px] text-muted hover:text-crit" wire:click="$set('confirmingDelete', true)">Delete</button>
                    @endif
                @endunless
                <div class="ml-auto flex gap-2">
                    <button type="button" class="btn" wire:click="cancel">Cancel</button>
                    <button type="submit" class="btn btn-solid">{{ $isNew ? 'Add record' : 'Save' }}</button>
                </div>
            </div>
        </form>
    </td>
</tr>
