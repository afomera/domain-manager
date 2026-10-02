<x-layouts::guest title="Choose a new password">
    <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-text-field label="Email" name="email" type="email" autocomplete="username" :value="old('email', $request->email)" required />
        <x-text-field label="New password" name="password" type="password" autocomplete="new-password" required autofocus />
        <x-text-field label="Confirm password" name="password_confirmation" type="password" autocomplete="new-password" required />
        <button type="submit" class="btn btn-solid justify-center">Reset password</button>
    </form>
</x-layouts::guest>
