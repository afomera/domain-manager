@props(['connection', 'firstImport' => false])

@php
    $percent = $connection->sync_progress['percent'] ?? 0;
    $message = $connection->sync_progress['message'] ?? 'Starting…';
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col gap-2.5 rounded-md border border-line p-4']) }} role="status" aria-live="polite">
    <div class="flex items-center justify-between gap-4 text-[13px]">
        <span class="flex items-center gap-2 font-medium">
            <x-lucide-refresh-cw class="icon animate-spin text-muted" />
            {{ $firstImport ? 'Importing your domains from Cloudflare' : 'Syncing with Cloudflare' }}
        </span>
        <span class="num text-muted">{{ $percent }}%</span>
    </div>
    <div class="h-1 overflow-hidden rounded-full bg-subtle" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
        <div class="h-full rounded-full bg-fg transition-[width] duration-700 ease-out" style="width: {{ max(2, $percent) }}%"></div>
    </div>
    <p class="truncate text-[12.5px] text-muted">{{ $message }}</p>
    @if ($connection->isWaitingForWorker())
        <p class="text-[12.5px] text-warn">
            Still waiting to start. Syncs run on the queue — is a worker running? Locally, <span class="font-mono">composer run dev</span> starts one.
        </p>
    @endif
</div>
