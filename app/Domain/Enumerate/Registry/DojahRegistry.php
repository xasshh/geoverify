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
 * Dojah, a licensed provider with links to CAC and FIRS.
 *
 * Endpoints as documented at docs.dojah.io: CAC by RC number and company type
 * (basic for search, advance for the record), TIN by the same pair, and a
 * global business search by name. Authenticated by the AppId header and the
 * secret key in Authorization, with no Bearer prefix.
 *
 * Nothing it returns is logged. A provider's answer about a company names its
 * directors, and a log line is the one copy nobody remembers to reduce.
 */
final class DojahRegistry implements RegistryLookup
{
    /** Tried in this order when a number is searched without a type. */
    private const TYPES = ['COMPANY', 'BUSINESS_NAME', 'INCORPORATED_TRUSTEES'];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $appId,
        private readonly string $secret,
    ) {
        if ($appId === '' || $secret === '') {
            throw new RuntimeException('DOJAH_APP_ID and DOJAH_SECRET_KEY must both be set to use the Dojah registry.');
        }
    }

    public function provider(): string
    {
        return 'dojah';
    }

    public function search(string $by, string $term): array
    {
        $term = trim($term);

        if ($by === 'rc') {
            $number = self::digits($term);

            foreach (self::TYPES as $type) {
                $entity = $this->get('/api/v1/kyc/cac/basic', ['rc_number' => $number, 'company_type' => $type]);

                if ($entity !== null && is_string($entity['company_name'] ?? null)) {
                    return [new RegistryMatch(
                        (string) $entity['company_name'],
                        (string) ($entity['rc_number'] ?? $number),
                        (string) ($entity['type_of_company'] ?? $type),
                        self::status($entity['status'] ?? null),
                        self::place($entity),
                    )];
                }
            }

            return [];
        }

        $entities = $this->get('/api/v1/kyb/business/search', ['name' => $term, 'country_code' => 'NG']);
        $matches = [];

        foreach (array_slice(is_array($entities) ? $entities : [], 0, 8) as $entity) {
            if (! is_array($entity) || ! is_string($entity['name'] ?? null)) {
                continue;
            }

            // The search gives a name and an "international number" and
            // nothing else. The number's prefix says which kind of
            // registration it is; its status comes with the record.
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
        $entity = $this->get('/api/v1/kyc/cac/advance', ['rc_number' => self::digits($rcNumber), 'company_type' => $companyType]);

        if ($entity === null || ! is_string($entity['company_name'] ?? null)) {
            return null;
        }

        $directors = [];

        foreach (is_array($entity['affiliates'] ?? null) ? $entity['affiliates'] : [] as $affiliate) {
            if (! is_array($affiliate)) {
                continue;
            }

            $name = trim(((string) ($affiliate['first_name'] ?? '')).' '.((string) ($affiliate['last_name'] ?? '')));

            if ($name === '') {
                continue;
            }

            // Name and role. The phone number, gender and nationality in the
            // same object are left where they are.
            $directors[] = ['name' => $name, 'role' => ucfirst(strtolower((string) ($affiliate['affiliate_type'] ?? 'Director')))];
        }

        return new CompanyRecord(
            (string) $entity['company_name'],
            (string) ($entity['rc_number'] ?? $rcNumber),
            (string) ($entity['type_of_company'] ?? $companyType),
            self::status($entity['status'] ?? null) ?? 'Unknown',
            is_string($entity['date_of_registration'] ?? null) ? substr($entity['date_of_registration'], 0, 10) : null,
            is_string($entity['address'] ?? null) ? $entity['address'] : self::place($entity),
            $directors,
        );
    }

    public function tin(string $rcNumber, string $companyType): ?TinRecord
    {
        $entity = $this->get('/api/v1/kyc/cac/tin', ['rc_number' => self::digits($rcNumber), 'company_type' => $companyType]);

        if ($entity === null || ! is_string($entity['tax_id'] ?? null) || $entity['tax_id'] === '') {
            return null;
        }

        return new TinRecord($entity['tax_id'], (string) ($entity['company_name'] ?? ''));
    }

    /**
     * The `entity` of an answer, null when the register has no such record.
     *
     * @param  array<string, string>  $query
     * @return array<mixed>|null
     */
    private function get(string $path, array $query): ?array
    {
        try {
            $response = $this->client()->get($path, $query);
        } catch (ConnectionException) {
            throw new RegistryUnavailable('The registry provider could not be reached.');
        }

        if ($response->status() === 404 || $response->status() === 400) {
            // Dojah answers a record it cannot find with a 400 or a 404 and
            // an error message; either way the register was asked.
            return null;
        }

        if (! $response->successful()) {
            Log::warning('The registry provider refused a lookup.', ['path' => $path, 'status' => $response->status()]);

            throw new RegistryUnavailable(self::reason($response));
        }

        $entity = $response->json('entity');

        return is_array($entity) ? $entity : null;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['AppId' => $this->appId, 'Authorization' => $this->secret])
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 500, throw: false);
    }

    private static function reason(Response $response): string
    {
        $error = $response->json('error');

        return 'The registry provider did not answer: '.(is_string($error) ? $error : "HTTP {$response->status()}");
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
