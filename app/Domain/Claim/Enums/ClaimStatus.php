<?php

declare(strict_types=1);

namespace App\Domain\Claim\Enums;

/**
 * Where a claim stands.
 *
 * `disputed` is a state with a resolution path rather than an error: a second
 * person claiming a listing is an ordinary event on a register built from
 * doorstep observation, and telling them "already claimed" and stopping would
 * leave a real owner with nowhere to go.
 */
enum ClaimStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Disputed = 'disputed';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'In review',
            self::Approved => 'Approved',
            self::Rejected => 'Not approved',
            self::Disputed => 'Disputed',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function isSettled(): bool
    {
        return $this === self::Approved || $this === self::Rejected || $this === self::Withdrawn;
    }

    /** Only an approved claim grants management of a listing. */
    public function grantsControl(): bool
    {
        return $this === self::Approved;
    }
}
