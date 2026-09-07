<?php

declare(strict_types=1);

namespace App\Domain\Verification\Enums;

/**
 * What the officer found, which is not the same as how the order went.
 *
 * Every one of these is a completed, billable verification. The officer
 * attended, recorded what was there and filed a report, and the customer has
 * the answer they paid for even when it is not the answer they wanted.
 */
enum OrderOutcome: string
{
    /** The business is there and trading, as claimed. */
    case Confirmed = 'confirmed';

    /** Nothing of that business at that address. */
    case NotFound = 'not_found';

    /** The premises are there and shut. */
    case Closed = 'closed';

    /** Somebody else is trading from it. */
    case DifferentOccupant = 'different_occupant';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmed at this address',
            self::NotFound => 'Not found at this address',
            self::Closed => 'Premises closed',
            self::DifferentOccupant => 'A different business is there',
        };
    }

    /** Whether this outcome establishes the tier that was ordered. */
    public function establishesTier(): bool
    {
        return $this === self::Confirmed;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
