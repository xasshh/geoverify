<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Enums;

/**
 * The kinds of thing a campaign can declare it is collecting.
 *
 * Declared, not yet collected. The field client gathers against Phase 1's fixed
 * schema; this is the written statement of what an exercise is for, which is
 * what a client is asking to see when they ask what we are recording about
 * their mining companies.
 */
enum CampaignFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Select = 'select';
    case MultiSelect = 'multiselect';
    case Boolean = 'boolean';
    case Photo = 'photo';
    case GeoPoint = 'geopoint';
    case File = 'file';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Number => 'Number',
            self::Date => 'Date',
            self::Select => 'Single choice',
            self::MultiSelect => 'Multiple choice',
            self::Boolean => 'Yes or no',
            self::Photo => 'Photograph',
            self::GeoPoint => 'Position',
            self::File => 'File',
        };
    }

    /** Whether an option list is meaningful, and therefore required. */
    public function takesOptions(): bool
    {
        return $this === self::Select || $this === self::MultiSelect;
    }

    /** @return list<array{value: string, label: string, takesOptions: bool}> */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'takesOptions' => $case->takesOptions(),
        ], self::cases());
    }
}
