<?php

declare(strict_types=1);

namespace App\Domain\Field\Enums;

use App\Domain\Coverage\Models\GridCell;

/**
 * The life of one cell's work, from a supervisor handing it out to a supervisor
 * accepting it back.
 */
enum AssignmentStatus: string
{
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Returned = 'returned';

    /** Superseded by a reassignment. Kept so history stays intact. */
    case Reassigned = 'reassigned';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'Assigned',
            self::InProgress => 'In progress',
            self::Submitted => 'Submitted',
            self::Accepted => 'Accepted',
            self::Returned => 'Returned',
            self::Reassigned => 'Reassigned',
        };
    }

    /** Still the officer's to work. Returned work is theirs again. */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Assigned, self::InProgress, self::Returned => true,
            self::Submitted, self::Accepted, self::Reassigned => false,
        };
    }

    /**
     * The cell status this assignment implies, so the coverage map and the
     * assignment list can never tell a supervisor different things.
     */
    public function cellStatus(): string
    {
        return match ($this) {
            self::Assigned => GridCell::STATUS_ASSIGNED,
            self::InProgress => GridCell::STATUS_IN_PROGRESS,
            self::Submitted => GridCell::STATUS_SUBMITTED,
            self::Accepted => GridCell::STATUS_ACCEPTED,
            self::Returned => GridCell::STATUS_RETURNED,
            self::Reassigned => GridCell::STATUS_UNASSIGNED,
        };
    }
}
