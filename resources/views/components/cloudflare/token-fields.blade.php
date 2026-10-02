@props(['model' => 'tokenForm'])

{{--
    API token + optional expiry, bound to a CloudflareTokenForm (default property name: tokenForm).
    The token is never drawn: the real input's text is transparent and a row of dots is drawn over it.
    It stays a plain text field (not type=password), so Safari and password managers leave it alone.
--}}
@php
    // Common token lifetimes; Cloudflare lets you choose any end date.
    $presets = [
        ['label' => '30 days', 'days' => 30],
        ['label' => '90 days', 'days' => 90],
        ['label' => '6 months', 'months' => 6],
        ['label' => '1 year', 'months' => 12],
    ];
@endphp

<label class="flex flex-col gap-1.5 text-[12px] text-muted" x-data="{ token: $wire.entangle(@js($model.'.token')) }">
    API token
    <span class="relative">
        <input
            type="text"
            x-model="token"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            data-1p-ignore
            data-lpignore="true"
            data-bwignore
            data-form-type="other"
            placeholder="Paste your token"
            class="field font-mono text-transparent caret-[var(--fg)] selection:bg-transparent placeholder:text-faint"
        >
        <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 right-0 left-0 flex items-center overflow-hidden px-2.5 font-mono text-[13px] whitespace-nowrap text-fg" x-text="'•'.repeat((token ?? '').length)"></span>
    </span>
    @error($model.'.token')
        <span class="text-[12.5px] text-crit">{{ $message }}</span>
    @else
        <span class="text-[12px] text-faint" x-text="token ? `${token.length} characters pasted. Encrypted before it’s stored; never shown again.` : 'Encrypted before it’s stored. It’s never shown again.'"></span>
    @enderror
</label>

<div class="flex flex-col gap-1.5 text-[12px] text-muted">
    <span>Expires (optional)</span>
    <x-date-picker wire:model="{{ $model }}.expiresOn" min="today" :presets="$presets" placeholder="No expiry" label="Token expiry date" class="w-72 max-w-full" />
    @error($model.'.expiresOn')
        <span class="text-[12.5px] text-crit">{{ $message }}</span>
    @else
        <span class="text-[12px] text-faint">Set the expiry you picked in Cloudflare and we’ll remind you 14 days before it’s time to rotate. Left blank, we use Cloudflare’s expiry, if the token has one.</span>
    @enderror
</div>
