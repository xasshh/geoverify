<?php

declare(strict_types=1);

namespace App\Domain\Registry\Enums;

/** Where a proposed correction stands. */
enum CorrectionStatus: string
{
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    /** Taken back by the party before anybody ruled on it. */
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'In review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Not accepted',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function isSettled(): bool
    {
        return $this !== self::Submitted;
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
