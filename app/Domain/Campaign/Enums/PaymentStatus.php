<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Enums;

/** Where the money has got to. Super admin only, like everything commercial. */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartPaid = 'part_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartPaid => 'Part paid',
            self::Paid => 'Paid',
        };
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
