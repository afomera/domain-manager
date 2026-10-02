<x-layouts::guest title="Sign in">
    <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-4">
        @csrf
        <x-text-field label="Email" name="email" type="email" autocomplete="username" required autofocus />
        <x-text-field label="Password" name="password" type="password" autocomplete="current-password" required />
        <div class="flex items-center justify-between text-[13px]">
            <label class="flex items-center gap-2">
                <x-checkbox name="remember" :checked="(bool) old('remember')" />
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="text-muted underline decoration-line underline-offset-4 hover:text-fg hover:decoration-fg">Forgot password?</a>
        </div>
        <button type="submit" class="btn btn-solid justify-center">Sign in</button>
    </form>

    @if (Route::has('register'))
        <p class="text-[13px] text-muted">
            No account yet? <a href="{{ route('register') }}" class="text-fg underline decoration-line underline-offset-4 hover:decoration-fg">Create one</a>
        </p>
    @endif
</x-layouts::guest>
