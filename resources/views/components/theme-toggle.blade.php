{{-- Shows the current theme (sun in light, moon in dark — switched in CSS so there's no flash on load). --}}
<button
    type="button"
    x-data="themeToggle"
    x-on:click="toggle()"
    x-bind:aria-label="label"
    x-tooltip="label"
    aria-label="Switch theme"
    {{ $attributes->merge(['class' => 'rounded-md p-2 text-muted hover:bg-subtle hover:text-fg']) }}
>
    <x-lucide-sun class="icon theme-icon-light" />
    <x-lucide-moon class="icon theme-icon-dark" />
</button>
