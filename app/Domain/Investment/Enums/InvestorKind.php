<?php

declare(strict_types=1);

namespace App\Domain\Investment\Enums;

enum InvestorKind: string
{
    case Fund = 'fund';
    case Bank = 'bank';
    case Dfi = 'dfi';
    case Corporate = 'corporate';
    case Angel = 'angel';
    case FamilyOffice = 'family_office';

    public function label(): string
    {
        return match ($this) {
            self::Fund => 'Investment fund',
            self::Bank => 'Bank or lender',
            self::Dfi => 'Development finance institution',
            self::Corporate => 'Corporate investor',
            self::Angel => 'Angel or syndicate',
            self::FamilyOffice => 'Family office',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
