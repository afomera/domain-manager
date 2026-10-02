<x-layouts::guest title="Create account">
    <form method="POST" action="{{ route('register') }}" class="flex flex-col gap-4">
        @csrf
        <x-text-field label="Name" name="name" autocomplete="name" required autofocus />
        <x-text-field label="Email" name="email" type="email" autocomplete="username" required />
        <div x-data="{ visible: false }" class="flex flex-col gap-1.5 text-[12px] text-muted">
            <div class="flex items-center justify-between">
                <label for="password">Password</label>
                <button
                    type="button"
                    aria-controls="password"
                    x-on:click="visible = ! visible"
                    x-bind:aria-pressed="visible"
                    x-text="visible ? 'Hide' : 'Show'"
                    class="underline decoration-line underline-offset-4 hover:text-fg hover:decoration-fg"
                >Show</button>
            </div>
            <input id="password" name="password" x-bind:type="visible ? 'text' : 'password'" type="password" autocomplete="new-password" required class="field">
            @error('password')
                <span class="text-[12.5px] text-crit">{{ $message }}</span>
            @else
                <span class="text-[12px] text-faint">At least 8 characters.</span>
            @enderror
        </div>
        <button type="submit" class="btn btn-solid justify-center">Create account</button>
    </form>

    <p class="text-[13px] text-muted">
        Already have an account? <a href="{{ route('login') }}" class="text-fg underline decoration-line underline-offset-4 hover:decoration-fg">Sign in</a>
    </p>
</x-layouts::guest>
