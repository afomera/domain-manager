{{-- Listens for Livewire's dispatch('toast', message: …) and for a flashed session('toast'). --}}
<div
    x-data="{
        message: '',
        visible: false,
        timer: null,
        show(message) {
            this.message = message;
            this.visible = true;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.visible = false, 2600);
        },
    }"
    {{-- Redirects can flash a 'toast' message to show on arrival (e.g. after verifying an email). --}}
    @if (session('toast')) x-init="show(@js(session('toast')))" @endif
    x-on:toast.window="show($event.detail.message)"
    x-show="visible"
    x-cloak
    role="status"
    class="fixed bottom-6 left-1/2 z-50 flex -translate-x-1/2 items-center gap-3 rounded-md bg-fg px-4 py-2.5 text-[13px] whitespace-nowrap text-bg shadow-lg"
>
    <span x-text="message"></span>
</div>
