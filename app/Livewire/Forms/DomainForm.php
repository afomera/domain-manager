<?php

namespace App\Livewire\Forms;

use App\Enums\Provider;
use App\Models\Domain;
use App\Models\User;
use App\Services\Rdap\RdapClient;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Details kept by hand: registrars without an API (like Squarespace), prices and notes.
 */
class DomainForm extends Form
{
    public ?Domain $domain = null;

    public string $name = '';

    public string $registrar = 'squarespace';

    public string $registered_on = '';

    public string $expires_on = '';

    public bool $auto_renew = false;

    public string $renewal_price = '';

    public string $note = '';

    public function edit(Domain $domain): void
    {
        $this->domain = $domain;
        $this->name = $domain->name;
        $this->registrar = $domain->registrar->value;
        $this->registered_on = $domain->registered_on?->toDateString() ?? '';
        $this->expires_on = $domain->expires_on?->toDateString() ?? '';
        $this->auto_renew = $domain->auto_renew;
        $this->renewal_price = $domain->renewal_price_cents === null ? '' : number_format($domain->renewal_price_cents / 100, 2, '.', '');
        $this->note = $domain->note ?? '';
    }

    /**
     * Registrar facts come from the Cloudflare API for Cloudflare Registrar domains, so only price and note are editable.
     */
    public function managesRegistration(): bool
    {
        return ! $this->domain?->isRegisteredWithCloudflare();
    }

    public function save(User $user, RdapClient $rdap): Domain
    {
        $this->name = strtolower(rtrim(trim($this->name), '.'));
        $this->renewal_price = ltrim(trim($this->renewal_price), '$');

        $this->validate([
            'name' => [
                Rule::requiredIf(! $this->domain),
                'regex:/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/',
                Rule::unique('domains')->where('user_id', $user->id)->ignore($this->domain),
            ],
            'registrar' => ['required', Rule::enum(Provider::class)],
            'registered_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:registered_on'],
            'auto_renew' => ['boolean'],
            'renewal_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'name.regex' => 'Enter a domain like example.com.',
            'name.unique' => 'That domain is already in your list.',
            'expires_on.after_or_equal' => 'Expiry must be after the registration date.',
            'renewal_price.*' => 'Enter a price like 20.00.',
        ]);

        $domain = $this->domain ?? $user->domains()->make(['name' => $this->name]);

        if ($this->managesRegistration()) {
            $registrar = Provider::from($this->registrar);

            $domain->fill([
                'registrar' => $registrar,
                'registered_on' => $this->registered_on ?: null,
                'expires_on' => $this->expires_on ?: null,
                'auto_renew' => $this->auto_renew,
            ]);

            if (! $domain->hasCloudflareZone()) {
                $domain->nameserver_provider = $registrar;
            }

            if (! $domain->exists && (! $domain->expires_on || ! $domain->registered_on)) {
                $this->fillFromRdap($domain, $rdap);
            }
        }

        $priceCents = $this->renewal_price === '' ? null : (int) round((float) $this->renewal_price * 100);

        // A price typed by hand wins over Cloudflare's; clearing it hands the price back to the sync.
        if ($priceCents !== $domain->renewal_price_cents || ! $domain->exists) {
            $domain->renewal_price_source = $priceCents === null ? null : Domain::PRICE_SET_MANUALLY;
        }

        $domain->fill([
            'renewal_price_cents' => $priceCents,
            'note' => trim($this->note) ?: null,
        ]);

        $domain->save();

        return $domain;
    }

    protected function fillFromRdap(Domain $domain, RdapClient $rdap): void
    {
        $registration = $rdap->lookup($domain->name);

        $domain->registrar_name = $registration?->registrarName;
        $domain->registered_on ??= $registration?->registeredOn;
        $domain->expires_on ??= $registration?->expiresOn;
    }
}
