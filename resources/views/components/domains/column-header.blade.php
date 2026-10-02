@props(['key', 'column', 'sort', 'direction', 'values' => [], 'counts' => []])

@php
    $isSorted = $sort === $key && isset($column['sort']);
    $isFiltered = collect($values)->contains(fn ($value, $property) => isset($column['filters'][$property]['options'][$value]));
    $alignRight = ($column['align'] ?? null) === 'right';
@endphp

<th
    @class(['text-right' => $alignRight])
    @if ($isSorted) aria-sort="{{ $direction === 'desc' ? 'descending' : 'ascending' }}" @endif
    x-data="{ open: false }"
    x-on:keydown.escape.window="open = false"
>
    <button
        type="button"
        x-ref="button"
        x-on:click="open = ! open"
        x-bind:aria-expanded="open"
        aria-haspopup="menu"
        @class(['inline-flex items-center gap-1 rounded px-1 -mx-1 hover:bg-subtle hover:text-fg', 'text-fg' => $isSorted || $isFiltered])
    >
        {{ $column['label'] }}
        @if ($isSorted)
            @if ($direction === 'desc')
                <x-lucide-arrow-down class="size-3" />
            @else
                <x-lucide-arrow-up class="size-3" />
            @endif
        @endif
        @if ($isFiltered)
            <span class="size-1.5 rounded-full bg-fg" aria-label="filtered"></span>
        @endif
        <x-lucide-chevron-down class="size-3 text-faint" />
    </button>

    {{-- Teleported so the menu isn't clipped by the table's horizontal scroll container. --}}
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            x-anchor.{{ $alignRight ? 'bottom-end' : 'bottom-start' }}.offset.4="$refs.button"
            x-on:click.outside="if (! $refs.button.contains($event.target)) open = false"
            role="menu"
            class="z-50 flex w-56 flex-col rounded-md border border-line bg-bg p-1 text-left text-[13px] text-fg shadow-lg"
        >
            @isset($column['sort'])
                <div class="px-2 pt-1.5 pb-1 text-[11px] text-muted">Sort</div>
                @foreach (['asc', 'desc'] as $index => $option)
                    @php($active = $isSorted && $direction === $option)
                    <button type="button" role="menuitemradio" aria-checked="{{ $active ? 'true' : 'false' }}" x-on:click="open = false; $wire.sortBy(@js($key), @js($option))" class="flex items-center gap-3 rounded px-2 py-1.5 hover:bg-subtle">
                        <span class="flex-1">{{ $column['sort'][$index] }}</span>
                        <span class="flex w-3.5 justify-center">@if ($active) <x-lucide-check class="size-3.5" /> @endif</span>
                    </button>
                @endforeach
            @endisset

            @foreach ($column['filters'] as $property => $group)
                @php($current = $values[$property] ?? '')
                @if (isset($column['sort']) || ! $loop->first)
                    <div class="my-1 border-t border-line"></div>
                @endif
                <div class="px-2 pt-1.5 pb-1 text-[11px] text-muted">{{ $group['label'] }}</div>
                @foreach (['' => 'Any'] + $group['options'] as $value => $label)
                    @php($active = $value === '' ? ! isset($group['options'][$current]) : $current === (string) $value)
                    @php($count = $counts[$property][(string) $value] ?? null)
                    <button type="button" role="menuitemradio" aria-checked="{{ $active ? 'true' : 'false' }}" x-on:click="open = false; $wire.filter(@js($property), @js((string) $value))" @class(['flex items-center gap-3 rounded px-2 py-1.5 hover:bg-subtle', 'text-faint' => $count === 0 && ! $active])>
                        <span class="flex-1">{{ $label }}</span>
                        @if ($count !== null) <span class="num text-[12px] text-muted">{{ $count }}</span> @endif
                        <span class="flex w-3.5 justify-center">@if ($active) <x-lucide-check class="size-3.5" /> @endif</span>
                    </button>
                @endforeach
            @endforeach
        </div>
    </template>
</th>
