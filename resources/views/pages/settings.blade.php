<?php

use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Events\PortfolioUpdated;
use App\Livewire\Forms\CloudflareTokenForm;
use App\Models\BrowserSession;
use App\Models\CloudflareConnection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Settings')] class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public CloudflareTokenForm $tokenForm;

    /**
     * Editing the expiry of the token that's already saved.
     */
    public string $tokenExpiresOn = '';

    public bool $replacingToken = false;

    public bool $editingExpiry = false;

    public bool $confirmingDisconnect = false;

    public bool $confirmingClearDomains = false;

    public bool $confirmingLogoutOthers = false;

    /**
     * Password confirmation for logging out other sessions; cleared right after use.
     */
    public string $logoutPassword = '';

    /**
     * Used in the broadcast channel name for live updates from other tabs and devices.
     */
    #[Locked]
    public int $userId;

    public function mount(): void
    {
        $this->userId = auth()->id();
        $this->name = auth()->user()->name;
        $this->email = auth()->user()->email;
    }

    #[Computed]
    public function connection(): ?CloudflareConnection
    {
        return auth()->user()->cloudflareConnection()->first();
    }

    public function saveProfile(UpdateUserProfileInformation $updater): void
    {
        $previousEmail = auth()->user()->email;

        // Changing the email clears verification and sends a link to the new address.
        $updater->update(auth()->user(), ['name' => $this->name, 'email' => $this->email]);

        $this->dispatch('toast', message: auth()->user()->email !== $previousEmail
            ? 'Saved. Check '.auth()->user()->email.' for a verification link.'
            : 'Profile saved');
    }

    public function resendVerification(): void
    {
        $user = auth()->user();

        if ($user->hasVerifiedEmail()) {
            return;
        }

        // Same allowance as Fortify's resend route: 6 per minute.
        $sent = RateLimiter::attempt('verify-email:'.$user->id, 6, fn () => $user->sendEmailVerificationNotification());

        $this->dispatch('toast', message: $sent ? 'Sent a new link to '.$user->email : 'Too many requests. Try again in a minute.');
    }

    /**
     * Cloudflare and bulk data actions need a verified email; the profile section doesn't.
     */
    protected function ensureVerified(): bool
    {
        if (auth()->user()->hasVerifiedEmail()) {
            return true;
        }

        $this->dispatch('toast', message: 'Verify your email first');

        return false;
    }

    public function savePassword(UpdateUserPassword $updater): void
    {
        $updater->update(auth()->user(), [
            'current_password' => $this->current_password,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->dispatch('toast', message: 'Password updated');
    }

    public function connectCloudflare(): void
    {
        if (! $this->ensureVerified()) {
            return;
        }

        $isFirstConnection = $this->connection === null;

        $connection = $this->tokenForm->connect(auth()->user());

        $this->reset('replacingToken');
        unset($this->connection);

        $connection->queueSync();

        // First connection: go watch the import fill the domain list.
        if ($isFirstConnection) {
            $this->redirectRoute('domains.index', navigate: true);
        }
    }

    public function editExpiry(): void
    {
        $this->tokenExpiresOn = $this->connection?->token_expires_on?->toDateString() ?? '';
        $this->editingExpiry = true;
    }

    public function saveExpiry(): void
    {
        $this->validate(['tokenExpiresOn' => ['nullable', 'date']], [
            'tokenExpiresOn.*' => 'Enter a date, or leave it blank.',
        ]);

        $this->connection?->update(['token_expires_on' => $this->tokenExpiresOn ?: null]);
        $this->reset('tokenExpiresOn', 'editingExpiry');
        unset($this->connection);

        $this->dispatch('toast', message: 'Token expiry saved');
    }

    public function sync(): void
    {
        if (! $this->ensureVerified()) {
            return;
        }

        $this->connection?->queueSync();
        unset($this->connection);
    }

    /**
     * Another tab or device changed something: refresh the connection and counts.
     */
    #[On('echo-private:App.Models.User.{userId},.portfolio.updated')]
    public function portfolioUpdated(): void
    {
        unset($this->connection, $this->domainCount);
    }

    /**
     * Stop waiting on a sync no worker has picked up.
     */
    public function cancelSync(): void
    {
        $this->connection?->cancelQueuedSync();
        unset($this->connection);

        $this->dispatch('toast', message: 'Sync cancelled');
    }

    /**
     * Polled while a sync runs.
     */
    public function checkSync(): void
    {
        unset($this->connection);
    }

    #[Computed]
    public function domainCount(): int
    {
        return auth()->user()->domains()->count();
    }

    /**
     * Remove every domain (and, via the foreign key, every DNS record) stored for this user.
     * Nothing changes in Cloudflare; the next sync imports Cloudflare's domains again.
     */
    public function clearDomains(): void
    {
        if (! $this->ensureVerified()) {
            return;
        }

        if ($this->connection?->isSyncing()) {
            $this->confirmingClearDomains = false;
            $this->dispatch('toast', message: 'Wait for the sync to finish first');

            return;
        }

        $removed = auth()->user()->domains()->delete();
        PortfolioUpdated::dispatch(auth()->id(), removed: true);

        // So the next sync shows as a fresh import.
        $this->connection?->update(['last_synced_at' => null, 'last_sync_error' => null]);

        $this->confirmingClearDomains = false;
        unset($this->connection, $this->domainCount);

        $this->dispatch('toast', message: 'Removed '.str('domain')->plural($removed)->prepend($removed.' '));
    }

    /**
     * Signed-in browsers for this account, current one first. Needs the database session driver.
     *
     * @return Collection<int, BrowserSession>
     */
    #[Computed]
    public function sessions(): Collection
    {
        if (config('session.driver') !== 'database') {
            return new Collection;
        }

        $currentId = session()->getId();

        return BrowserSession::query()
            ->whereBelongsTo(auth()->user())
            ->orderByDesc('last_activity')
            ->get()
            ->sortByDesc(fn (BrowserSession $session) => $session->id === $currentId)
            ->values();
    }

    /**
     * Sign out every other browser, including "remember me" ones: rehashing the password invalidates
     * their remember cookies, and the auth.session middleware ends their sessions on the next request.
     */
    public function logoutOtherSessions(): void
    {
        $this->validate(['logoutPassword' => ['required', 'string', 'current_password:web']], [
            'logoutPassword.required' => 'Enter your password to confirm.',
            'logoutPassword.current_password' => 'That password isn’t right.',
        ]);

        Auth::logoutOtherDevices($this->logoutPassword);

        $removed = BrowserSession::query()
            ->whereBelongsTo(auth()->user())
            ->whereKeyNot(session()->getId())
            ->delete();

        $this->reset('logoutPassword', 'confirmingLogoutOthers');
        unset($this->sessions);

        $this->dispatch('toast', message: $removed
            ? 'Logged out of '.str('other session')->plural($removed)->prepend($removed.' ')
            : 'Other sessions logged out');
    }

    /**
     * Development helper: go through onboarding again. Not available outside local.
     */
    public function restartOnboarding(): void
    {
        abort_unless(app()->isLocal(), 404);

        auth()->user()->forceFill(['onboarded_at' => null])->save();

        $this->redirectRoute('onboarding', navigate: true);
    }

    public function disconnect(): void
    {
        $this->connection?->delete();
        PortfolioUpdated::dispatch(auth()->id());
        $this->reset('confirmingDisconnect', 'replacingToken');
        $this->tokenForm->reset();
        unset($this->connection);

        $this->dispatch('toast', message: 'Cloudflare disconnected');
    }
};
?>

@php
    $connection = $this->connection;
    $user = auth()->user();
    $isVerified = $user->hasVerifiedEmail();
    // Common token lifetimes; Cloudflare lets you choose any end date.
    $expiryPresets = [
        ['label' => '30 days', 'days' => 30],
        ['label' => '90 days', 'days' => 90],
        ['label' => '6 months', 'months' => 6],
        ['label' => '1 year', 'months' => 12],
    ];
@endphp

<main class="mx-auto flex max-w-6xl flex-col gap-10 px-4 py-10 sm:px-6">
    <h1 class="text-2xl font-semibold tracking-tight">Settings</h1>

    @unless ($isVerified)
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px]" role="status">
            <x-lucide-circle-alert class="icon text-warn" />
            <span>Verify <span class="font-medium">{{ $user->email }}</span> to connect Cloudflare and manage domains.</span>
            <button type="button" wire:click="resendVerification" class="underline decoration-line underline-offset-4 hover:decoration-fg">Resend link</button>
        </div>
    @endunless

    <section class="grid gap-6 border-t border-line pt-6 lg:grid-cols-[240px_1fr]" aria-labelledby="cloudflare-heading">
        <div class="flex flex-col gap-1">
            <h2 id="cloudflare-heading" class="font-medium">Cloudflare</h2>
            <p class="text-[13px] text-muted">Imports your zones, DNS records and Registrar domains, and sends DNS changes back.</p>
        </div>

        <div class="flex max-w-xl flex-col gap-5">
            @if (! $isVerified)
                <p class="text-[13px] text-muted">Available once your email is verified.</p>
            @elseif ($connection)
                <dl class="text-[13px] [&>div]:flex [&>div]:justify-between [&>div]:gap-4 [&>div]:border-t [&>div]:border-line [&>div]:py-2 [&>div:first-child]:border-t-0 [&_dt]:text-muted [&_dd]:num [&_dd]:text-right">
                    <div><dt>API token</dt><dd class="font-mono text-[12.5px]">••••••••{{ $connection->token_hint }}</dd></div>
                    <div>
                        <dt>Token expires</dt>
                        <dd>
                            @if ($editingExpiry)
                                <form wire:submit="saveExpiry" class="flex items-center justify-end gap-2">
                                    <x-date-picker wire:model="tokenExpiresOn" :presets="$expiryPresets" placeholder="No expiry" label="Token expiry date" class="w-64" />
                                    <button type="submit" class="btn">Save</button>
                                    <button type="button" class="btn" wire:click="$set('editingExpiry', false)">Cancel</button>
                                </form>
                                @error('tokenExpiresOn') <span class="text-[12.5px] text-crit">{{ $message }}</span> @enderror
                            @else
                                <span @class(['text-crit' => $connection->tokenHasExpired(), 'text-warn' => $connection->tokenNeedsRotation() && ! $connection->tokenHasExpired()])>
                                    {{ $connection->token_expires_on?->format('M j, Y') ?? 'Not set' }}
                                </span>
                                · <button type="button" class="text-muted underline decoration-line underline-offset-4 hover:text-fg hover:decoration-fg" wire:click="editExpiry">{{ $connection->token_expires_on ? 'Change' : 'Set' }}</button>
                            @endif
                        </dd>
                    </div>
                    <div><dt>Verified</dt><dd>{{ $connection->verified_at?->diffForHumans() ?? 'Never' }}</dd></div>
                    <div><dt>Last synced</dt><dd>{{ $connection->last_synced_at?->diffForHumans() ?? 'Never' }}</dd></div>
                </dl>

                @if ($connection->isSyncing())
                    <div wire:poll.1s="checkSync">
                        <x-sync-progress :connection="$connection" :first-import="$connection->last_synced_at === null" />
                    </div>
                @endif

                @if ($reminder = $connection->rotationReminder())
                    <div class="flex items-start gap-3 text-[13px]">
                        <x-lucide-circle-alert @class(['icon mt-0.5', 'text-crit' => $connection->tokenHasExpired(), 'text-warn' => ! $connection->tokenHasExpired()]) />
                        <span>{{ $reminder }} Create a new token in Cloudflare, then use Replace token below.</span>
                    </div>
                @endif

                @if ($connection->last_sync_error)
                    <div class="flex items-start gap-3 text-[13px]">
                        <x-lucide-circle-alert class="icon mt-0.5 text-warn" />
                        <span>{{ $connection->last_sync_error }}</span>
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn btn-solid" wire:click="sync" @disabled($connection->isSyncing())>
                        <x-lucide-refresh-cw @class(['icon', 'animate-spin' => $connection->isSyncing()]) />{{ $connection->isSyncing() ? 'Syncing…' : 'Sync now' }}
                    </button>
                    @unless ($replacingToken)
                        <button type="button" class="btn" wire:click="$set('replacingToken', true)">Replace token</button>
                    @endunless
                    <div class="ml-auto flex items-center gap-2">
                        @if ($confirmingDisconnect)
                            <span class="text-[13px]">Disconnect? Domains stay, but stop syncing.</span>
                            <button type="button" class="btn text-crit" wire:click="disconnect">Disconnect</button>
                            <button type="button" class="btn" wire:click="$set('confirmingDisconnect', false)">Keep</button>
                        @else
                            <button type="button" class="text-[13px] text-muted hover:text-crit" wire:click="$set('confirmingDisconnect', true)">Disconnect</button>
                        @endif
                    </div>
                </div>
            @endif

            @if ($isVerified && (! $connection || $replacingToken))
                <form wire:submit="connectCloudflare" class="flex flex-col gap-4">
                    <x-cloudflare.token-fields />
                    <div class="flex items-center gap-2">
                        <button type="submit" class="btn btn-solid" wire:loading.attr="disabled" wire:target="connectCloudflare">
                            <span wire:loading.remove wire:target="connectCloudflare">{{ $connection ? 'Save token' : 'Connect' }}</span>
                            <span wire:loading wire:target="connectCloudflare">Verifying and syncing…</span>
                        </button>
                        @if ($replacingToken)
                            <button type="button" class="btn" wire:click="$set('replacingToken', false)">Cancel</button>
                        @endif
                    </div>
                </form>

                <x-cloudflare.token-help />
            @endif
        </div>
    </section>

    <section class="grid gap-6 border-t border-line pt-6 lg:grid-cols-[240px_1fr]" aria-labelledby="profile-heading">
        <h2 id="profile-heading" class="font-medium">Profile</h2>
        <form wire:submit="saveProfile" class="flex max-w-xl flex-col gap-4">
            <x-text-field label="Name" name="name" wire:model="name" autocomplete="name" />
            <div class="flex flex-col gap-1.5">
                <x-text-field label="Email" name="email" type="email" wire:model="email" autocomplete="username" />
                @if ($isVerified)
                    <span class="flex items-center gap-1 text-[12px] text-muted"><x-lucide-check class="size-3.5" />Verified</span>
                @else
                    <span class="text-[12px] text-muted">
                        <span class="text-warn">Not verified.</span> We sent a link to {{ $user->email }}.
                        <button type="button" wire:click="resendVerification" class="underline decoration-line underline-offset-4 hover:text-fg hover:decoration-fg">Resend link</button>
                    </span>
                @endif
            </div>
            <div><button type="submit" class="btn btn-solid">Save profile</button></div>
        </form>
    </section>

    <section class="grid gap-6 border-t border-line pt-6 lg:grid-cols-[240px_1fr]" aria-labelledby="password-heading">
        <h2 id="password-heading" class="font-medium">Password</h2>
        <form wire:submit="savePassword" class="flex max-w-xl flex-col gap-4">
            <x-text-field label="Current password" name="current_password" type="password" wire:model="current_password" autocomplete="current-password" />
            <x-text-field label="New password" name="password" type="password" wire:model="password" autocomplete="new-password" />
            <x-text-field label="Confirm new password" name="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password" />
            <div><button type="submit" class="btn btn-solid">Update password</button></div>
        </form>
    </section>
    <section class="grid gap-6 border-t border-line pt-6 lg:grid-cols-[240px_1fr]" aria-labelledby="sessions-heading">
        <div class="flex flex-col gap-1">
            <h2 id="sessions-heading" class="font-medium">Sessions</h2>
            <p class="text-[13px] text-muted">Browsers signed in to your account.</p>
        </div>
        <div class="flex max-w-xl flex-col gap-4">
            @if (config('session.driver') !== 'database')
                <p class="text-[13px] text-muted">Session listing needs <span class="font-mono">SESSION_DRIVER=database</span>.</p>
            @else
                <ul class="flex flex-col text-[13px]">
                    @foreach ($this->sessions as $browserSession)
                        @php($isCurrent = $browserSession->id === session()->getId())
                        <li wire:key="session-{{ $browserSession->id }}" class="flex items-center gap-3 border-t border-line py-2.5 first:border-t-0">
                            @if ($browserSession->isMobile())
                                <x-lucide-smartphone class="icon text-muted" />
                            @else
                                <x-lucide-laptop class="icon text-muted" />
                            @endif
                            <div class="flex min-w-0 flex-1 flex-col">
                                <span>{{ $browserSession->description() }}</span>
                                <span class="text-[12px] text-muted">
                                    <span class="font-mono">{{ $browserSession->ip_address ?? 'Unknown IP' }}</span>
                                    ·
                                    @if ($isCurrent)
                                        <span class="text-fg">This device</span>
                                    @else
                                        Active {{ $browserSession->lastActiveAt()->diffForHumans() }}
                                    @endif
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($this->sessions->count() > 1)
                    @if ($confirmingLogoutOthers)
                        <form wire:submit="logoutOtherSessions" class="flex flex-col gap-3 rounded-md border border-line bg-subtle p-4">
                            <x-text-field label="Your password" name="logoutPassword" type="password" wire:model="logoutPassword" autocomplete="current-password" x-init="$el.focus()" />
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-solid" wire:loading.attr="disabled" wire:target="logoutOtherSessions">Log out other sessions</button>
                                <button type="button" class="btn" wire:click="$set('confirmingLogoutOthers', false)">Cancel</button>
                            </div>
                        </form>
                    @else
                        <div>
                            <button type="button" class="btn" wire:click="$set('confirmingLogoutOthers', true)">
                                <x-lucide-log-out class="icon" />Log out other sessions
                            </button>
                        </div>
                    @endif
                @else
                    <p class="text-[12.5px] text-faint">You’re only signed in here.</p>
                @endif
            @endif
        </div>
    </section>

    @if ($isVerified)
    <section class="grid gap-6 border-t border-line pt-6 lg:grid-cols-[240px_1fr]" aria-labelledby="data-heading">
        <div class="flex flex-col gap-1">
            <h2 id="data-heading" class="font-medium">Data</h2>
            <p class="text-[13px] text-muted">Start over with a clean list.</p>
        </div>
        <div class="flex max-w-xl flex-col gap-3">
            <p class="text-[13px] text-muted">
                Removes every domain and DNS record stored here. Nothing changes in Cloudflare or at your registrars — the next sync imports your Cloudflare domains again. Domains you added by hand are gone for good.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                @if ($confirmingClearDomains)
                    <span class="text-[13px]">Remove all {{ Str::plural('domain', $this->domainCount, prependCount: true) }}?</span>
                    <button type="button" class="btn text-crit" wire:click="clearDomains">Remove all</button>
                    <button type="button" class="btn" wire:click="$set('confirmingClearDomains', false)">Keep</button>
                @else
                    <button type="button" class="btn text-crit" wire:click="$set('confirmingClearDomains', true)" @disabled($this->domainCount === 0 || $connection?->isSyncing())>
                        <x-lucide-trash-2 class="icon" />Remove all domains
                    </button>
                    @if ($this->domainCount === 0)
                        <span class="text-[12.5px] text-faint">No domains stored.</span>
                    @endif
                @endif
            </div>
        </div>
    </section>
    @endif

    @if (app()->isLocal())
        <section class="grid gap-6 border-t border-line pt-6 lg:grid-cols-[240px_1fr]" aria-labelledby="dev-heading">
            <div class="flex flex-col gap-1">
                <h2 id="dev-heading" class="font-medium">Development</h2>
                <p class="text-[13px] text-muted">Only shown when running locally.</p>
            </div>
            <div class="flex max-w-xl flex-col gap-3">
                <p class="text-[13px] text-muted">Go through the welcome flow again. Steps you’ve already done (like a verified email) are skipped.</p>
                <div>
                    <button type="button" class="btn" wire:click="restartOnboarding">
                        <x-lucide-rotate-ccw class="icon" />Restart onboarding
                    </button>
                </div>
            </div>
        </section>
    @endif
</main>
