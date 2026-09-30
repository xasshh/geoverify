<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Registry;

use RuntimeException;

/**
 * A register that answers from a fixed list, for development and tests.
 *
 * Refuses to exist in production: a fake that answered there would be issuing
 * reports about real businesses from made-up records. Each entry exercises one
 * path the desk check has to handle: a clean pass, a TIN held under a
 * different name, a struck-off company, a business name, and a number the
 * provider cannot answer for.
 */
final class FakeRegistry implements RegistryLookup
{
    /** Asking about this number makes the fake behave like a provider outage. */
    public const UNAVAILABLE_RC = '9999999';

    /** @var list<array{name: string, rc: string, type: string, status: string, incorporated: string, address: string, place: string, tin: string|null, tinName: string|null, directors: list<array{name: string, role: string}>}> */
    private const COMPANIES = [
        [
            'name' => 'KORA BUILD SUPPLIES LIMITED', 'rc' => '1482093', 'type' => 'COMPANY', 'status' => 'Active',
            'incorporated' => '2016-03-14', 'address' => 'Plot 7, Ahmadu Bello Way, Garki, Abuja', 'place' => 'Garki, Abuja',
            'tin' => '23984417-0001', 'tinName' => 'KORA BUILD SUPPLIES LIMITED',
            'directors' => [['name' => 'Kolawole Ade', 'role' => 'Director'], ['name' => 'Rasheedat Ade', 'role' => 'Director']],
        ],
        [
            'name' => 'KORA BUILDING SUPPLIES & SERVICES', 'rc' => '3309127', 'type' => 'BUSINESS_NAME', 'status' => 'Inactive',
            'incorporated' => '2019-06-02', 'address' => 'Kubwa, Abuja', 'place' => 'Kubwa, Abuja',
            'tin' => null, 'tinName' => null, 'directors' => [['name' => 'Musa Ibrahim', 'role' => 'Proprietor']],
        ],
        [
            'name' => 'SAHEL SOLAR SYSTEMS LIMITED', 'rc' => '1739021', 'type' => 'COMPANY', 'status' => 'Active',
            'incorporated' => '2020-01-20', 'address' => '14 Aminu Kano Crescent, Wuse 2, Abuja', 'place' => 'Wuse, Abuja',
            'tin' => '31100452-0001', 'tinName' => 'SAHEL SOLAR SYSTEMS LIMITED',
            'directors' => [['name' => 'Hauwa Sani', 'role' => 'Director']],
        ],
        [
            'name' => 'AMAKA FRESH FOODS LIMITED', 'rc' => '1620554', 'type' => 'COMPANY', 'status' => 'Active',
            'incorporated' => '2018-11-05', 'address' => '3 Ogui Road, Enugu', 'place' => 'Enugu North, Enugu',
            'tin' => '20877310-0001', 'tinName' => 'AMAKA FRESH FOODS LIMITED',
            'directors' => [['name' => 'Amaka Obi', 'role' => 'Director'], ['name' => 'Chidi Obi', 'role' => 'Director']],
        ],
        [
            'name' => 'ACME VENTURES NIGERIA LIMITED', 'rc' => '1101187', 'type' => 'COMPANY', 'status' => 'Active',
            'incorporated' => '2013-04-09', 'address' => '22 Allen Avenue, Ikeja, Lagos', 'place' => 'Ikeja, Lagos',
            // Held under a trading name FIRS was never told had changed.
            'tin' => '10244981-0001', 'tinName' => 'ACME GENERAL MERCHANTS',
            'directors' => [['name' => 'Tunde Bakare', 'role' => 'Director']],
        ],
        [
            'name' => 'OLD HARBOUR TRADING COMPANY LIMITED', 'rc' => '0412288', 'type' => 'COMPANY', 'status' => 'Struck off',
            'incorporated' => '2002-08-30', 'address' => '5 Marina, Lagos Island, Lagos', 'place' => 'Lagos Island, Lagos',
            'tin' => '01288412-0001', 'tinName' => 'OLD HARBOUR TRADING COMPANY LIMITED',
            'directors' => [['name' => 'Emeka Nwosu', 'role' => 'Director']],
        ],
    ];

    public function __construct(bool $production)
    {
        if ($production) {
            throw new RuntimeException('The fake registry cannot run in production. Set REGISTRY_DRIVER=dojah and its keys.');
        }
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function search(string $by, string $term): array
    {
        $term = trim($term);

        if ($by === 'rc' && self::digits($term) === self::UNAVAILABLE_RC) {
            throw new RegistryUnavailable('The registry provider could not be reached.');
        }

        $found = array_filter(self::COMPANIES, static fn (array $c): bool => $by === 'rc'
            ? $c['rc'] === self::digits($term)
            : $term !== '' && str_contains($c['name'], mb_strtoupper($term)));

        return array_values(array_map(static fn (array $c): RegistryMatch => new RegistryMatch(
            $c['name'], $c['rc'], $c['type'], $c['status'], $c['place'],
        ), $found));
    }

    public function company(string $rcNumber, string $companyType): ?CompanyRecord
    {
        $c = $this->find($rcNumber);

        return $c === null ? null : new CompanyRecord(
            $c['name'], $c['rc'], $c['type'], $c['status'], $c['incorporated'], $c['address'], $c['directors'],
        );
    }

    public function tin(string $rcNumber, string $companyType): ?TinRecord
    {
        $c = $this->find($rcNumber);

        return $c === null || $c['tin'] === null ? null : new TinRecord($c['tin'], (string) $c['tinName']);
    }

    /** @return array{name: string, rc: string, type: string, status: string, incorporated: string, address: string, place: string, tin: string|null, tinName: string|null, directors: list<array{name: string, role: string}>}|null */
    private function find(string $rcNumber): ?array
    {
        if (self::digits($rcNumber) === self::UNAVAILABLE_RC) {
            throw new RegistryUnavailable('The registry provider could not be reached.');
        }

        foreach (self::COMPANIES as $c) {
            if ($c['rc'] === self::digits($rcNumber)) {
                return $c;
            }
        }

        return null;
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }
}
