@props(['label', 'name', 'type' => 'text', 'hint' => null, 'error' => null, 'noAutofill' => false])

{{-- noAutofill opts out of browser autofill and password managers (1Password, LastPass, Bitwarden). --}}

@php($message = $error ?? $errors->first($name))

<label class="flex flex-col gap-1.5 text-[12px] text-muted">
    {{ $label }}
    <input
        type="{{ $type }}"
        name="{{ $name }}"
        @if ($type !== 'password' && ! $attributes->has('value') && ! $attributes->whereStartsWith('wire:model')->isNotEmpty()) value="{{ old($name) }}" @endif
        @if ($noAutofill) autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" @endif
        {{ $attributes->merge(['class' => 'field']) }}
    >
    @if ($message)
        <span class="text-[12.5px] text-crit">{{ $message }}</span>
    @elseif ($hint)
        <span class="text-[12px] text-faint">{{ $hint }}</span>
    @endif
</label>
