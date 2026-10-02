@props([
    'placeholder' => 'Pick a date',
    'min' => null,
    'presets' => [],
    'label' => null,
])

{{--
    Calendar date picker bound with wire:model to a "YYYY-MM-DD" string property ("" when empty).
    Presets: [['label' => '90 days', 'days' => 90], ['label' => '1 year', 'months' => 12], …]
--}}
<div
    x-data="datePicker({ min: @js($min), presets: @js($presets) })"
    x-modelable="value"
    {{ $attributes->whereStartsWith('wire:model') }}
    {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'relative']) }}
    x-on:keydown.escape.window="open && close()"
>
    <button
        type="button"
        x-ref="trigger"
        x-on:click="toggle()"
        x-bind:aria-expanded="open"
        aria-haspopup="dialog"
        @if ($label) aria-label="{{ $label }}" @endif
        class="field flex w-full items-center gap-2 text-left"
    >
        <x-lucide-calendar class="icon text-muted" />
        <span class="min-w-0 flex-1 truncate">
            <span x-show="value" x-text="label" class="num"></span>
            <span x-show="value" x-text="'· ' + relative" class="text-muted"></span>
            <span x-show="! value" class="text-faint">{{ $placeholder }}</span>
        </span>
        <x-lucide-chevron-down class="icon text-muted" />
    </button>

    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            x-anchor.bottom-start.offset.4="$refs.trigger"
            x-on:click.outside="if (! $refs.trigger.contains($event.target)) close(false)"
            role="dialog"
            aria-label="{{ $label ?? 'Choose a date' }}"
            class="z-50 flex w-72 flex-col gap-3 rounded-md border border-line bg-bg p-3 text-[13px] text-fg shadow-lg"
        >
            <template x-if="presets.length">
                <div class="flex flex-wrap gap-1.5">
                    <template x-for="preset in presets" :key="preset.label">
                        <button type="button" x-on:click="applyPreset(preset)" x-text="preset.label" class="rounded-full border border-line px-2.5 py-0.5 text-[12px] hover:bg-subtle"></button>
                    </template>
                </div>
            </template>

            <div class="flex items-center justify-between">
                <button type="button" x-on:click="shiftMonth(-1)" class="rounded-md p-1 text-muted hover:bg-subtle hover:text-fg" aria-label="Previous month">
                    <x-lucide-chevron-left class="icon" />
                </button>
                <span x-text="monthLabel" class="font-medium" aria-live="polite"></span>
                <button type="button" x-on:click="shiftMonth(1)" class="rounded-md p-1 text-muted hover:bg-subtle hover:text-fg" aria-label="Next month">
                    <x-lucide-chevron-right class="icon" />
                </button>
            </div>

            <div class="grid grid-cols-7 text-center text-[11px] text-muted">
                <template x-for="weekday in weekdays" :key="weekday">
                    <span x-text="weekday" class="py-1"></span>
                </template>
            </div>

            <div x-ref="grid" role="grid" class="-mt-2 grid grid-cols-7 gap-y-0.5 text-center" x-on:keydown="onGridKey($event)">
                <template x-for="day in days" :key="day.iso">
                    <button
                        type="button"
                        role="gridcell"
                        x-bind:data-date="day.iso"
                        x-bind:tabindex="day.isFocused ? 0 : -1"
                        x-bind:disabled="day.isDisabled"
                        x-bind:aria-selected="day.isSelected"
                        x-bind:aria-label="new Date(day.iso + 'T00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })"
                        x-on:click="pick(day.iso)"
                        x-text="day.day"
                        class="num mx-auto flex size-8 items-center justify-center rounded-md focus:outline-none focus-visible:ring-1 focus-visible:ring-fg disabled:cursor-not-allowed disabled:opacity-30"
                        x-bind:class="{
                            'bg-fg text-bg': day.isSelected,
                            'hover:bg-subtle': ! day.isSelected && ! day.isDisabled,
                            'text-faint': ! day.inMonth && ! day.isSelected,
                            'ring-1 ring-line': day.isToday && ! day.isSelected,
                        }"
                    ></button>
                </template>
            </div>

            <div class="flex items-center justify-between border-t border-line pt-2 text-[12px]">
                <span class="text-muted">Arrow keys to move, Enter to pick</span>
                <button type="button" x-show="value" x-on:click="clear()" class="text-muted underline decoration-line underline-offset-4 hover:text-fg hover:decoration-fg">Clear</button>
            </div>
        </div>
    </template>
</div>
