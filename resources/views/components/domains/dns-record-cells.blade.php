@props(['record'])

<td class="font-mono text-[12.5px]">{{ $record->type->value }}</td>
<td class="font-mono text-[12.5px] break-all">{{ $record->name }}</td>
<td class="font-mono text-[12.5px] break-all text-muted">
    @if ($record->priority !== null)
        <span class="text-faint">{{ $record->priority }}</span>
    @endif
    {{ $record->content }}
</td>
<td class="text-muted">{{ $record->ttlLabel() }}</td>
<td @class(['text-muted' => ! $record->proxied])>
    <span class="inline-flex items-center gap-1.5">
        @if ($record->proxied)
            <x-lucide-cloud class="icon fill-proxy text-proxy" />Proxied
        @else
            <x-lucide-cloud-off class="icon" />DNS only
        @endif
    </span>
</td>
