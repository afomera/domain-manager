<?php

namespace App\Livewire\Forms;

use App\Enums\DnsRecordType;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Services\Cloudflare\CloudflareException;
use App\Services\Cloudflare\CloudflareSync;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

class DnsRecordForm extends Form
{
    private const string HOSTNAME_PATTERN = '/^([a-z0-9_]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}\.?$/i';

    private const string RECORD_NAME_PATTERN = '/^(@|\*|(\*\.)?[a-z0-9_]([a-z0-9_.-]*[a-z0-9_])?)$/i';

    public ?DnsRecord $record = null;

    public string $type = 'A';

    public string $name = '';

    public string $content = '';

    public int $ttl = 1;

    public bool $proxied = true;

    public string $priority = '10';

    public function edit(DnsRecord $record): void
    {
        $this->resetErrorBag();
        $this->record = $record;
        $this->type = $record->type->value;
        $this->name = $record->name;
        $this->content = $record->content;
        $this->ttl = $record->ttl;
        $this->proxied = $record->proxied;
        $this->priority = (string) ($record->priority ?? 10);
    }

    public function updatedType(): void
    {
        if (! $this->recordType()->isProxyable()) {
            $this->proxied = false;
        }
    }

    public function updatedProxied(): void
    {
        if ($this->proxied) {
            $this->ttl = 1;
        }
    }

    /**
     * The standard TTL choices, plus the record's current TTL if Cloudflare holds a non-standard one.
     *
     * @return array<int, string>
     */
    public function ttlOptions(): array
    {
        $options = DnsRecord::TTL_OPTIONS;

        if ($this->record && ! isset($options[$this->record->ttl])) {
            $options[$this->record->ttl] = DnsRecord::ttlLabelFor($this->record->ttl);
            ksort($options);
        }

        return $options;
    }

    public function recordType(): DnsRecordType
    {
        return DnsRecordType::tryFrom($this->type) ?? DnsRecordType::A;
    }

    /**
     * Validate and persist the record, returning it.
     */
    public function save(Domain $domain): DnsRecord
    {
        $this->name = $this->relativeName($domain);
        $type = $this->recordType();
        $proxied = $type->isProxyable() && $this->proxied;

        $this->validate($this->rulesFor($domain));

        $attributes = [
            'type' => $type,
            'name' => $this->name,
            'content' => trim($this->content),
            'ttl' => $proxied ? 1 : $this->ttl,
            'proxied' => $proxied,
            'priority' => $type === DnsRecordType::MX ? (int) $this->priority : null,
        ];

        if ($domain->hasCloudflareZone()) {
            $attributes = $this->pushToCloudflare($domain, $attributes);
        }

        if ($this->record) {
            $this->record->update($attributes);

            return $this->record;
        }

        return $domain->dnsRecords()->create($attributes);
    }

    /**
     * Write the record through the Cloudflare API and return the attributes Cloudflare stored.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function pushToCloudflare(Domain $domain, array $attributes): array
    {
        $payload = [
            'type' => $attributes['type']->value,
            'name' => DnsRecord::fullyQualifiedName($attributes['name'], $domain->name),
            'content' => $attributes['content'],
            'ttl' => $attributes['ttl'],
            'proxied' => $attributes['proxied'],
        ];

        if ($attributes['priority'] !== null) {
            $payload['priority'] = $attributes['priority'];
        }

        try {
            $client = $domain->cloudflareClient();
            $remote = $this->record?->cloudflare_id
                ? $client->updateDnsRecord($domain->cloudflare_zone_id, $this->record->cloudflare_id, $payload)
                : $client->createDnsRecord($domain->cloudflare_zone_id, $payload);
        } catch (CloudflareException $exception) {
            throw ValidationException::withMessages(['form.cloudflare' => 'Cloudflare: '.$exception->getMessage()]);
        }

        return ['cloudflare_id' => $remote['id']] + CloudflareSync::recordAttributes($remote, $attributes['type'], $domain->name);
    }

    /**
     * Accepts "www", "www.example.com" or "example.com" and stores the zone-relative name ("@" for the apex).
     */
    protected function relativeName(Domain $domain): string
    {
        $input = trim($this->name);

        if ($input === '') {
            return '';
        }

        $relative = preg_replace('/\.?'.preg_quote($domain->name, '/').'\.?$/i', '', $input);

        return $relative === '' ? '@' : $relative;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rulesFor(Domain $domain): array
    {
        $type = $this->recordType();

        $contentRules = match ($type) {
            DnsRecordType::A => ['ipv4'],
            DnsRecordType::AAAA => ['ipv6'],
            DnsRecordType::CNAME, DnsRecordType::MX => ['regex:'.self::HOSTNAME_PATTERN],
            DnsRecordType::TXT => ['max:2048'],
        };

        return [
            'type' => ['required', Rule::in(array_column(DnsRecordType::editable(), 'value'))],
            'name' => ['required', 'regex:'.self::RECORD_NAME_PATTERN, $this->noCnameConflict($domain)],
            'content' => ['required', 'string', ...$contentRules],
            'ttl' => ['required', Rule::in(array_keys($this->ttlOptions()))],
            'proxied' => ['boolean'],
            'priority' => $type === DnsRecordType::MX ? ['required', 'integer', 'between:0,65535'] : ['nullable'],
        ];
    }

    /**
     * A CNAME must be the only record at its name.
     */
    protected function noCnameConflict(Domain $domain): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($domain): void {
            $others = $domain->dnsRecords()
                ->when($this->record, fn ($query) => $query->whereKeyNot($this->record->id))
                ->whereRaw('lower(name) = ?', [strtolower($value)])
                ->get();

            if ($this->recordType() === DnsRecordType::CNAME && $others->isNotEmpty()) {
                $fail("A CNAME can’t share a name with other records. Remove the {$others->first()->type->value} record on “{$value}” first.");
            } elseif ($others->contains('type', DnsRecordType::CNAME)) {
                $fail("“{$value}” already has a CNAME record. Remove it first, or pick another name.");
            }
        };
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        $type = $this->recordType();

        return [
            'name.required' => 'Enter a name like @, www or mail. Use @ for the root domain.',
            'name.regex' => 'Enter a name like @, www or mail. Use @ for the root domain.',
            'content.required' => match ($type) {
                DnsRecordType::TXT => 'TXT content can’t be empty.',
                default => 'Enter the record’s '.strtolower($type->contentLabel()).'.',
            },
            'content.ipv4' => 'Enter an IPv4 address, like 192.0.2.10.',
            'content.ipv6' => 'Enter an IPv6 address, like 2001:db8::1.',
            'content.regex' => $type === DnsRecordType::MX
                ? 'Enter a hostname, like mx1.example.com.'
                : 'Enter a hostname, like target.example.com.',
            'content.max' => 'TXT content is limited to 2,048 characters.',
            'priority.*' => 'Priority must be a whole number from 0 to 65535.',
            'ttl.*' => 'Pick a TTL from the list.',
        ];
    }
}
