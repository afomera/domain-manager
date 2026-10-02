{{--
    An 18px checkbox drawn to match the app: neutral border, filled with the foreground color when checked.
    Pass the usual attributes (name, wire:model, disabled, checked…); they go on the real input.
--}}
<span class="relative inline-flex size-[18px] shrink-0 items-center justify-center">
    <input
        type="checkbox"
        {{ $attributes->merge(['class' => 'peer size-[18px] cursor-pointer appearance-none rounded-[5px] border border-line bg-bg transition-colors hover:border-muted checked:border-fg checked:bg-fg checked:hover:border-fg focus-visible:outline-1 focus-visible:outline-offset-2 focus-visible:outline-fg disabled:cursor-not-allowed disabled:opacity-40']) }}
    >
    <x-lucide-check class="pointer-events-none absolute size-3 stroke-[3] text-bg opacity-0 transition-opacity peer-checked:opacity-100" aria-hidden="true" />
</span>
