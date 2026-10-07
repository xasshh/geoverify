<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Enums;

/**
 * What the officers on a campaign are equipped to record.
 *
 * Buildings is the field platform as built. Area features is the land between
 * and beyond them: forest, farmland, rivers, water points. A campaign may run
 * either or both; one that names neither is refused by the database.
 */
enum CaptureMode: string
{
    case Buildings = 'buildings';
    case AreaFeatures = 'area_features';

    public function label(): string
    {
        return match ($this) {
            self::Buildings => 'Buildings and businesses',
            self::AreaFeatures => 'Area and natural features',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(static fn (self $mode): array => [
            'value' => $mode->value,
            'label' => $mode->label(),
        ], self::cases());
    }
}
