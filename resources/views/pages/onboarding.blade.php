<?php

use App\Livewire\Forms\CloudflareTokenForm;
use App\Models\CloudflareConnection;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * First-run setup: confirm your email → connect Cloudflare → first import (on the domains page).
 * Steps that are already done are skipped.
 */
new #[Layout('layouts::guest', ['wide' => true, 'hideHeading' => true])] #[Title('Welcome')] class extends Component
{
    public CloudflareTokenForm $tokenForm;

    public bool $replacingToken = false;

    public function mount(): void
    {
        if ($this->user->hasVerifiedEmail() && $this->user->isOnboarded()) {
            $this->redirectRoute('domains.index', navigate: true);
        }
    }

    #[Computed]
    public function user(): User
    {
        return auth()->user();
    }

    #[Computed]
    public function connection(): ?CloudflareConnection
    {
        return $this->user->cloudflareConnection()->first();
    }

    /**
     * "email" until the address is confirmed, then "cloudflare".
     */
    #[Computed]
    public function step(): string
    {
        return $this->user->hasVerifiedEmail() ? 'cloudflare' : 'email';
    }

    /**
     * Polled on the email step, so clicking the link in another tab moves this one along.
     */
    public function checkVerified(): void
    {
        $this->user->refresh();
        unset($this->step);

        if ($this->step === 'cloudflare') {
            $this->dispatch('toast', message: 'Email verified');
        }
    }

    public function resendVerification(): void
    {
        if ($this->user->hasVerifiedEmail()) {
            return;
        }

        // Same allowance as Fortify's resend route: 6 per minute.
        $sent = RateLimiter::attempt('verify-email:'.$this->user->id, 6, fn () => $this->user->sendEmailVerificationNotification());

        $this->dispatch('toast', message: $sent ? 'Sent a new link to '.$this->user->email : 'Too many requests. Try again in a minute.');
    }

    /**
     * Verify and store the token, start the first import, and go watch it on the domains page.
     */
    public function connect(): void
    {
        if ($this->step !== 'cloudflare') {
            return;
        }

        $this->tokenForm->connect($this->user)->queueSync();

        $this->redirectRoute('domains.index', navigate: true);
    }

    /**
     * Already connected (say, set up from Settings before): finish without asking for the token again.
     */
    public function continueWithConnection(): void
    {
        if ($this->step !== 'cloudflare' || ! $this->connection) {
            return;
        }

        $this->user->markOnboarded();

        if ($this->connection->last_synced_at === null) {
            $this->connection->queueSync();
        }

        $this->redirectRoute('domains.index', navigate: true);
    }

    /**
     * No Cloudflare (yet): domains can be added by hand, and Cloudflare connected later in Settings.
     */
    public function skip(): void
    {
        if ($this->step !== 'cloudflare') {
            return;
        }

        $this->user->markOnboarded();
        $this->redirectRoute('domains.index', navigate: true);
    }
};
?>

@php
    $steps = ['email' => 'Confirm email', 'cloudflare' => 'Connect Cloudflare', 'import' => 'Import domains'];
    $currentIndex = array_search($this->step, array_keys($steps), true);
    $connection = $this->connection;
@endphp

<div class="flex flex-col gap-8">
    {{-- Step bars: the current step in the accent color, finished steps a softer tint, upcoming ones muted. --}}
    <ol class="grid grid-cols-3 gap-2" aria-label="Setup progress, step {{ $currentIndex + 1 }} of {{ count($steps) }}">
        @foreach ($steps as $key => $label)
            @php($index = $loop->index)
            <li class="flex flex-col gap-2" @if ($index === $currentIndex) aria-current="step" @endif>
                <span @class([
                    'h-1 rounded-full transition-colors duration-500',
                    'bg-accent' => $index === $currentIndex,
                    'bg-accent/35' => $index < $currentIndex,
                    'bg-line' => $index > $currentIndex,
                ])></span>
                <span @class([
                    'flex items-center gap-1 text-[12px]',
                    'font-medium text-fg' => $index === $currentIndex,
                    'text-muted' => $index < $currentIndex,
                    'text-faint' => $index > $currentIndex,
                ])>
                    @if ($index < $currentIndex)
                        <x-lucide-check class="size-3" aria-hidden="true" /><span class="sr-only">Done:</span>
                    @endif
                    {{ $label }}
                </span>
            </li>
        @endforeach
    </ol>

    @if ($this->step === 'email')
        <section class="flex flex-col gap-5" wire:poll.3s="checkVerified">
            <div class="flex flex-col gap-2">
                <h1 class="text-2xl font-semibold tracking-tight">Confirm your email</h1>
                <p class="text-[13px] text-muted">
                    We sent a link to <span class="text-fg">{{ $this->user->email }}</span>. Open it on any device — this page moves on by itself.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="btn btn-solid" wire:click="resendVerification">Send the link again</button>
                <a href="{{ route('settings') }}" class="text-[13px] text-muted underline decoration-line underline-offset-4 hover:text-fg hover:decoration-fg">Wrong address?</a>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-[13px] text-muted underline decoration-line underline-offset-4 hover:text-fg hover:decoration-fg">Use a different account</button>
            </form>
        </section>
    @else
        <section class="flex flex-col gap-5">
            <div class="flex flex-col gap-2">
                <h1 class="text-2xl font-semibold tracking-tight">Connect Cloudflare</h1>
                <p class="text-[13px] text-muted">
                    We’ll import your zones, DNS records and Registrar domains, then keep them in sync every hour.
                </p>
            </div>

            @if ($connection && ! $replacingToken)
                <div class="flex items-center gap-3 rounded-md border border-line p-4 text-[13px]">
                    <x-lucide-check class="icon text-fg" />
                    <span class="flex-1">Already connected with token <span class="font-mono text-[12.5px]">••••••••{{ $connection->token_hint }}</span></span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" class="btn btn-solid" wire:click="continueWithConnection">Continue</button>
                    <button type="button" class="text-[13px] text-muted underline decoration-line underline-offset-4 hover:text-fg hover:decoration-fg" wire:click="$set('replacingToken', true)">Use a different token</button>
                </div>
            @else
                <form wire:submit="connect" class="flex flex-col gap-4">
                    <x-cloudflare.token-fields />
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="btn btn-solid" wire:loading.attr="disabled" wire:target="connect">
                            <span wire:loading.remove wire:target="connect">Connect and import</span>
                            <span wire:loading wire:target="connect">Checking the token…</span>
                        </button>
                        @if ($connection)
                            <button type="button" class="btn" wire:click="$set('replacingToken', false)">Cancel</button>
                        @endif
                    </div>
                </form>

                <x-cloudflare.token-help />
            @endif

            <div class="border-t border-line pt-4 text-[13px] text-muted">
                Not using Cloudflare yet?
                <button type="button" wire:click="skip" class="text-fg underline decoration-line underline-offset-4 hover:decoration-fg">Skip for now</button>
                — add domains by hand, and connect later in Settings.
            </div>
        </section>
    @endif
</div>
