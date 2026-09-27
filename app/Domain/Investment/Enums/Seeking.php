<?php

declare(strict_types=1);

namespace App\Domain\Investment\Enums;

/** What a business says it is looking for. Closed, and checked in the table. */
enum Seeking: string
{
    case ExpansionEquity = 'expansion_equity';
    case GrowthEquity = 'growth_equity';
    case AssetFinance = 'asset_finance';
    case WorkingCapital = 'working_capital';
    case JointVenture = 'joint_venture';
    case Acquisition = 'acquisition';

    public function label(): string
    {
        return match ($this) {
            self::ExpansionEquity => 'Expansion equity',
            self::GrowthEquity => 'Growth equity',
            self::AssetFinance => 'Asset finance',
            self::WorkingCapital => 'Working capital',
            self::JointVenture => 'Joint venture',
            self::Acquisition => 'Acquisition',
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
