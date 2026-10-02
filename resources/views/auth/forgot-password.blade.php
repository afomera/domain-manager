<x-layouts::guest title="Reset password">
    <p class="text-[13px] text-muted">Enter your email and we’ll send you a link to choose a new password.</p>

    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-4">
        @csrf
        <x-text-field label="Email" name="email" type="email" autocomplete="username" required autofocus />
        <button type="submit" class="btn btn-solid justify-center">Send reset link</button>
    </form>

    <p class="text-[13px] text-muted">
        <a href="{{ route('login') }}" class="text-fg underline decoration-line underline-offset-4 hover:decoration-fg">Back to sign in</a>
    </p>
</x-layouts::guest>
