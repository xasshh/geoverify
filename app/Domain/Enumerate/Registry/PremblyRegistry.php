<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Registry;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Prembly (IdentityPass), a licensed provider with links to CAC and FIRS.
 *
 * Chosen on 2026-10-09 in place of Dojah. Endpoints as documented at
 * docs.prembly.com, all POST with a JSON body: CAC basic and advance by
 * registration number and type, TIN by the RC number on the CAC channel, and
 * the global company search by name. Authenticated by the x-api-key header,
 * with app-id sent too because the older endpoints ask for it.
 *
 * Prembly speaks of company types as RC, BN and IT; the rest of the system
 * keeps the CAC words (COMPANY, BUSINESS_NAME, INCORPORATED_TRUSTEES), and
 * this class translates in both directions.
 *
 * Nothing it returns is logged. A provider's answer about a company names its
 * directors, and a log line is the one copy nobody remembers to reduce.
 */
final class PremblyRegistry implements RegistryLookup
{
    /** Ours to Prembly's, tried in this order when a number has no type. */
    private const TYPES = ['COMPANY' => 'RC', 'BUSINESS_NAME' => 'BN', 'INCORPORATED_TRUSTEES' => 'IT'];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $appId,
    ) {
        if ($apiKey === '') {
            throw new RuntimeException('PREMBLY_API_KEY must be set to use the Prembly registry.');
        }
    }

    public function provider(): string
    {
        return 'prembly';
    }

    public function search(string $by, string $term): array
    {
        $term = trim($term);

        if ($by === 'rc') {
            $number = self::digits($term);

            foreach (self::TYPES as $ours => $theirs) {
                $entity = self::first($this->post('/verification/cac/basic', ['rc_number' => $number, 'company_type' => $theirs]));

                if ($entity !== null && is_string($entity['company_name'] ?? null)) {
                    return [new RegistryMatch(
                        (string) $entity['company_name'],
                        self::digits((string) ($entity['rc_number'] ?? $number)) ?: $number,
                        self::ours($entity['company_type'] ?? $entity['entity_type'] ?? null) ?? $ours,
                        self::status($entity['company_status'] ?? null),
                        self::place($entity),
                    )];
                }
            }

            return [];
        }

        $entities = $this->post('/identitypass/verification/global/company/search', ['country_code' => 'ng', 'company_name' => $term]);
        $matches = [];

        foreach (array_slice(is_array($entities) && array_is_list($entities) ? $entities : [], 0, 8) as $entity) {
            if (! is_array($entity) || ! is_string($entity['name'] ?? null)) {
                continue;
            }

            $international = (string) ($entity['internationalNumber'] ?? '');
            $number = self::digits($international);

            if ($number === '') {
                continue;
            }

            $matches[] = new RegistryMatch($entity['name'], $number, self::typeFromPrefix($international));
        }

        return $matches;
    }

    public function company(string $rcNumber, string $companyType): ?CompanyRecord
    {
        $entity = self::first($this->post('/verification/cac/advance', [
            'rc_number' => self::digits($rcNumber),
            'company_type' => self::TYPES[$companyType] ?? 'RC',
        ]));

        if ($entity === null || ! is_string($entity['company_name'] ?? null)) {
            return null;
        }

        $directors = [];

        foreach (is_array($entity['directors'] ?? null) ? $entity['directors'] : [] as $director) {
            if (! is_array($director)) {
                continue;
            }

            $name = trim(implode(' ', array_filter(
                [$director['firstname'] ?? null, $director['otherName'] ?? null, $director['surname'] ?? null],
                static fn (mixed $part): bool => is_string($part) && trim($part) !== '' && strtoupper(trim($part)) !== 'N/A',
            )));

            if ($name === '') {
                continue;
            }

            $role = is_array($director['affiliateTypeFk'] ?? null) ? ($director['affiliateTypeFk']['name'] ?? null) : null;

            // Name and role. The phone, email and home address in the same
            // object are left where they are.
            $directors[] = ['name' => $name, 'role' => ucfirst(strtolower(is_string($role) && $role !== '' ? $role : 'Director'))];
        }

        return new CompanyRecord(
            (string) $entity['company_name'],
            self::digits((string) ($entity['rc_number'] ?? $rcNumber)) ?: self::digits($rcNumber),
            self::ours($entity['company_type'] ?? $entity['entity_type'] ?? null) ?? $companyType,
            self::status($entity['company_status'] ?? null) ?? 'Unknown',
            is_string($entity['registrationDate'] ?? null) ? substr($entity['registrationDate'], 0, 10) : null,
            is_string($entity['company_address'] ?? null) && $entity['company_address'] !== '' ? $entity['company_address'] : self::place($entity),
            $directors,
        );
    }

    public function tin(string $rcNumber, string $companyType): ?TinRecord
    {
        // On the CAC channel the number carries its prefix: RC123456, BN123456.
        $entity = $this->post('/verification/tin', [
            'number' => (self::TYPES[$companyType] ?? 'RC').self::digits($rcNumber),
            'channel' => 'CAC',
        ]);

        $tin = is_array($entity) ? ($entity['firstin'] ?? $entity['tin'] ?? $entity['jittin'] ?? null) : null;

        if (! is_string($tin) || trim($tin) === '') {
            return null;
        }

        return new TinRecord(trim($tin), (string) ($entity['taxpayer_name'] ?? ''));
    }

    /**
     * The `data` of an answer, null when the register has no such record.
     *
     * @param  array<string, string>  $body
     * @return array<mixed>|null
     */
    private function post(string $path, array $body): ?array
    {
        try {
            $response = $this->client()->post($path, $body);
        } catch (ConnectionException) {
            throw new RegistryUnavailable('The registry provider could not be reached.');
        }

        // Prembly answers a record it cannot find with a 400 or 404 (often an
        // empty body), or a 200 whose status is false: the register was asked.
        if (in_array($response->status(), [400, 404, 422], true)) {
            return null;
        }

        if (! $response->successful()) {
            Log::warning('The registry provider refused a lookup.', ['path' => $path, 'status' => $response->status()]);

            throw new RegistryUnavailable(self::reason($response));
        }

        if ($response->json('status') !== true) {
            return null;
        }

        $data = $response->json('data');

        return is_array($data) ? $data : null;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(array_filter(['x-api-key' => $this->apiKey, 'app-id' => $this->appId]))
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(2, 500, throw: false);
    }

    /**
     * Advance CAC answers with a list; basic with one object.
     *
     * @param  array<mixed>|null  $data
     * @return array<mixed>|null
     */
    private static function first(?array $data): ?array
    {
        if ($data === null || $data === []) {
            return null;
        }

        if (array_is_list($data)) {
            return is_array($data[0]) ? $data[0] : null;
        }

        return $data;
    }

    private static function reason(Response $response): string
    {
        $detail = $response->json('detail') ?? $response->json('message');

        return 'The registry provider did not answer: '.(is_string($detail) ? $detail : "HTTP {$response->status()}");
    }

    private static function ours(mixed $type): ?string
    {
        if (! is_string($type)) {
            return null;
        }

        $found = array_search(strtoupper(trim($type)), self::TYPES, true);

        return is_string($found) ? $found : (array_key_exists(strtoupper($type), self::TYPES) ? strtoupper($type) : null);
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    private static function typeFromPrefix(string $international): string
    {
        $prefix = strtoupper((string) preg_replace('/[^A-Za-z]+/', '', $international));

        return match (true) {
            str_contains($prefix, 'BN') => 'BUSINESS_NAME',
            str_contains($prefix, 'IT') => 'INCORPORATED_TRUSTEES',
            default => 'COMPANY',
        };
    }

    private static function status(mixed $status): ?string
    {
        return is_string($status) && $status !== '' ? ucfirst(strtolower($status)) : null;
    }

    /** @param  array<mixed>  $entity */
    private static function place(array $entity): ?string
    {
        $parts = array_filter(
            [$entity['city'] ?? null, $entity['lga'] ?? null, $entity['state'] ?? null],
            static fn (mixed $p): bool => is_string($p) && $p !== '',
        );

        return $parts === [] ? null : implode(', ', array_unique($parts));
    }
}
