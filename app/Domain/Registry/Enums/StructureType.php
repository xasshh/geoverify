<?php

declare(strict_types=1);

namespace App\Domain\Registry\Enums;

/**
 * What the business operates out of.
 *
 * The non building types are the point. A kiosk, a container and an umbrella
 * stand are where a large share of Nigerian commerce happens, and excluding them
 * would undercount exactly the informal economy a reform body is asking about.
 * They are structures with no footprint, positioned from GPS alone.
 */
enum StructureType: string
{
    case Shophouse = 'shophouse';
    case CommercialBlock = 'commercial_block';
    case Standalone = 'standalone';
    case Warehouse = 'warehouse';
    case Residential = 'residential';
    case UnderConstruction = 'under_construction';

    // Not buildings. No footprint, and none expected.
    case Kiosk = 'kiosk';
    case Container = 'container';
    case OpenStall = 'open_stall';
    case UmbrellaStand = 'umbrella_stand';
    case Mobile = 'mobile';

    public function label(): string
    {
        return match ($this) {
            self::Shophouse => 'Shophouse',
            self::CommercialBlock => 'Commercial block',
            self::Standalone => 'Standalone building',
            self::Warehouse => 'Warehouse',
            self::Residential => 'Residential',
            self::UnderConstruction => 'Under construction',
            self::Kiosk => 'Kiosk',
            self::Container => 'Shipping container',
            self::OpenStall => 'Open stall',
            self::UmbrellaStand => 'Umbrella stand',
            self::Mobile => 'Mobile trader',
        };
    }

    /**
     * Whether a detected footprint should be expected for this type.
     *
     * A kiosk with no matching footprint is normal and must not be scored as a
     * suspicious capture; a shophouse with none is worth a look.
     */
    public function expectsFootprint(): bool
    {
        return match ($this) {
            self::Kiosk, self::Container, self::OpenStall,
            self::UmbrellaStand, self::Mobile => false,
            default => true,
        };
    }

    /**
     * Whether asking how many storeys this thing has is a sensible question.
     *
     * An umbrella stand does not have one storey, it has none, and a capture
     * screen that asks anyway teaches officers to type a number to get past a
     * field. The types that are not buildings are the same ones that expect no
     * footprint, which is not a coincidence: it is the same distinction.
     */
    public function expectsFloors(): bool
    {
        return $this->expectsFootprint();
    }

    /** @return list<array{value: string, label: string, expectsFootprint: bool, expectsFloors: bool}> */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'expectsFootprint' => $case->expectsFootprint(),
            'expectsFloors' => $case->expectsFloors(),
        ], self::cases());
    }
}
