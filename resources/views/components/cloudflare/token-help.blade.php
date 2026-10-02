<div {{ $attributes->merge(['class' => 'flex flex-col gap-2 text-[12.5px] text-muted']) }}>
    <p>
        Create a token at
        <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener" class="text-fg underline decoration-line underline-offset-4 hover:decoration-fg">dash.cloudflare.com/profile/api-tokens</a>
        (under My Profile, not an account-owned token) with these permissions, for all zones in your account:
    </p>
    <ul class="flex flex-col gap-1 font-mono text-[12px] text-fg">
        <li>Zone · Zone · Read</li>
        <li>Zone · DNS · Edit</li>
        <li>Account · Registrar Domains · Admin <span class="font-sans text-muted">— optional; adds expiry dates and the auto‑renew toggle (Read works without the toggle)</span></li>
    </ul>
</div>
