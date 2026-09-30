<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Enums;

/**
 * How deep a check goes. Each tier includes everything in the tiers before it.
 */
enum Tier: int
{
    case Registry = 1;
    case Location = 2;
    case Activity = 3;

    public const MONITORING_PERIODS = [7, 14, 30];

    public function label(): string
    {
        return match ($this) {
            self::Registry => 'Registry check',
            self::Location => 'Location verification',
            self::Activity => 'Daily activity',
        };
    }

    /** "Tier 2 · Location", as the tables print it. */
    public function short(): string
    {
        return match ($this) {
            self::Registry => 'Tier 1 · Registry',
            self::Location => 'Tier 2 · Location',
            self::Activity => 'Tier 3 · Activity',
        };
    }

    public function sendsAnOfficer(): bool
    {
        return $this !== self::Registry;
    }
}
