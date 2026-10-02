{{--
    A native <select> styled to match .field, with our own chevron instead of the browser's.
    Width, font and layout classes go on the wrapper; everything else (wire:model, disabled…) goes on the select.
--}}
<div {{ $attributes->only('class')->merge(['class' => 'relative']) }}>
    <select {{ $attributes->except('class')->merge(['class' => 'field cursor-pointer appearance-none pr-8 disabled:cursor-not-allowed']) }}>
        {{ $slot }}
    </select>
    <x-lucide-chevron-down class="icon pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 text-muted" aria-hidden="true" />
</div>
