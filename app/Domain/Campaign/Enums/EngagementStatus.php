<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Enums;

/** How far the conversation with a stakeholder has got. */
enum EngagementStatus: string
{
    case Identified = 'identified';
    case Contacted = 'contacted';
    case Engaged = 'engaged';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Identified => 'Identified',
            self::Contacted => 'Contacted',
            self::Engaged => 'Engaged',
            self::Declined => 'Declined',
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
