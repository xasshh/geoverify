<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Enums;

/**
 * What one attribute of a feature class holds.
 *
 * Narrower than CampaignFieldType on purpose. Photographs and positions are
 * part of every capture already, so a class never declares them as attributes.
 */
enum AttributeType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Select = 'select';
    case MultiSelect = 'multiselect';
    case Boolean = 'boolean';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Number => 'Number',
            self::Date => 'Date',
            self::Select => 'Single choice',
            self::MultiSelect => 'Multiple choice',
            self::Boolean => 'Yes or no',
        };
    }

    public function takesOptions(): bool
    {
        return $this === self::Select || $this === self::MultiSelect;
    }

    /** @return list<array{value: string, label: string, takesOptions: bool}> */
    public static function options(): array
    {
        return array_map(static fn (self $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'takesOptions' => $type->takesOptions(),
        ], self::cases());
    }
}
