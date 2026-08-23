<?php

declare(strict_types=1);

namespace App\Domain\Registry\Enums;

/**
 * What the officer found. Refused and inaccessible are first class outcomes, not
 * failures: an officer who could not get in has done their job by saying so, and
 * a register that only records successes overstates its own coverage.
 */
enum OccupancyStatus: string
{
    case Occupied = 'occupied';
    case Vacant = 'vacant';
    case UnderConstruction = 'under_construction';
    case Demolished = 'demolished';
    case Refused = 'refused';
    case Inaccessible = 'inaccessible';

    public function label(): string
    {
        return match ($this) {
            self::Occupied => 'Occupied',
            self::Vacant => 'Vacant',
            self::UnderConstruction => 'Under construction',
            self::Demolished => 'Demolished',
            self::Refused => 'Access refused',
            self::Inaccessible => 'Could not reach',
        };
    }

    /** Whether businesses are expected inside. */
    public function expectsEnterprises(): bool
    {
        return $this === self::Occupied;
    }

    /** @return list<array{value: string, label: string, expectsEnterprises: bool}> */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'expectsEnterprises' => $case->expectsEnterprises(),
        ], self::cases());
    }
}
