<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

        <script>
            {{-- Applied before paint so a stored theme never flashes the wrong colors. --}}
            window.applyStoredTheme = () => {
                try {
                    const theme = localStorage.getItem('dcc-theme');
                    if (theme) document.documentElement.dataset.theme = theme;
                } catch {}
            };
            applyStoredTheme();
            document.addEventListener('livewire:navigated', applyStoredTheme);
        </script>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body x-data x-on:keydown.window.slash="if (! ['INPUT', 'SELECT', 'TEXTAREA'].includes($event.target.tagName)) { $event.preventDefault(); document.querySelector('[data-search]')?.focus() }">
        <header class="border-b border-line">
            <div class="mx-auto flex h-14 max-w-6xl items-center gap-6 px-4 sm:px-6">
                <a href="{{ route('domains.index') }}" wire:navigate class="flex shrink-0 items-center gap-2 font-medium">
                    <x-lucide-globe class="icon" />
                    <span class="hidden sm:inline">{{ config('app.name') }}</span>
                </a>
                <nav class="flex items-center gap-5 overflow-x-auto text-[13.5px] text-muted" aria-label="Primary">
                    <a href="{{ route('domains.index') }}" wire:navigate @class(['hover:text-fg', 'text-fg' => request()->routeIs('domains.*')])>Domains</a>
                </nav>
                <div class="ml-auto flex items-center gap-1">
                    <x-theme-toggle />
                    <a href="{{ route('settings') }}" wire:navigate @class(['rounded-md p-2 hover:bg-subtle hover:text-fg', 'text-fg' => request()->routeIs('settings'), 'text-muted' => ! request()->routeIs('settings')]) aria-label="Settings" x-tooltip="'Settings'">
                        <x-lucide-settings class="icon" />
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-md p-2 text-muted hover:bg-subtle hover:text-fg" aria-label="Log out" x-tooltip="'Log out'">
                            <x-lucide-log-out class="icon" />
                        </button>
                    </form>
                </div>
            </div>
        </header>

        {{ $slot }}

        <footer class="mx-auto flex max-w-6xl justify-center px-4 pt-8 pb-12 sm:px-6">
            <a href="https://afomera.dev" target="_blank" rel="noopener" class="block w-28 transition-opacity hover:opacity-80" aria-label="Made by Andrea Fomera" x-tooltip.top="'Made by Andrea Fomera'">
                <x-afomera-mark class="h-auto w-full" />
            </a>
        </footer>

        <x-toast />
    </body>
</html>
