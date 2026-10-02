<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

        <script>
            try {
                const theme = localStorage.getItem('dcc-theme');
                if (theme) document.documentElement.dataset.theme = theme;
            } catch {}
        </script>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        {{-- $wide: a roomier column (onboarding). $hideHeading: the page draws its own heading. --}}
        <main @class(['mx-auto flex min-h-dvh flex-col justify-center gap-6 px-4 py-12', 'max-w-md' => $wide ?? false, 'max-w-sm' => ! ($wide ?? false)])>
            <div class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <a href="{{ route('login') }}" class="flex items-center gap-2 font-medium">
                        <x-lucide-globe class="icon" />{{ config('app.name') }}
                    </a>
                    <x-theme-toggle class="-mr-2" />
                </div>
                @unless ($hideHeading ?? false)
                    <h1 class="text-2xl font-semibold tracking-tight">{{ $title }}</h1>
                @endunless
            </div>

            @if (session('status') && session('status') !== 'verification-link-sent')
                <p class="text-[13px] text-muted" role="status">{{ session('status') }}</p>
            @endif

            {{ $slot }}

            <a href="https://afomera.dev" target="_blank" rel="noopener" class="mt-6 block w-24 self-center transition-opacity hover:opacity-80" aria-label="Made by Andrea Fomera" x-tooltip.top="'Made by Andrea Fomera'">
                <x-afomera-mark class="h-auto w-full" />
            </a>
        </main>

        <x-toast />

        {{-- Livewire's bundled Alpine, for small interactions like the password toggle. --}}
        @livewireScripts
    </body>
</html>
