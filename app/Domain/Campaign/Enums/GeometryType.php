<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Enums;

/** The shape a feature class is drawn as. Fixed per class, never per feature. */
enum GeometryType: string
{
    case Point = 'point';
    case Line = 'line';
    case Polygon = 'polygon';

    public function label(): string
    {
        return match ($this) {
            self::Point => 'Point',
            self::Line => 'Line',
            self::Polygon => 'Area',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(static fn (self $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
        ], self::cases());
    }
}
