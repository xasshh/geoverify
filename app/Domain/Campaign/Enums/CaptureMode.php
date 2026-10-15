<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Enums;

/**
 * What the officers on a campaign are equipped to record.
 *
 * Buildings is the field platform as built. Area features is the land between
 * and beyond them: forest, farmland, rivers, water points. Polling units is a
 * list of places to visit and check before an election (app/Domain/Election).
 * A campaign may run any of them; one that names none is refused by the
 * database.
 */
enum CaptureMode: string
{
    case Buildings = 'buildings';
    case AreaFeatures = 'area_features';
    case PollingUnits = 'polling_units';

    public function label(): string
    {
        return match ($this) {
            self::Buildings => 'Buildings and businesses',
            self::AreaFeatures => 'Area and natural features',
            self::PollingUnits => 'Polling unit checks',
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
