<?php

declare(strict_types=1);

namespace App\Domain\Field\Actions;

use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Enums\AssignmentStatus;
use App\Domain\Field\Models\Assignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Takes a cell back off an officer without pretending it was never theirs.
 *
 * The assignment closes as reassigned and the cell returns to unassigned. The
 * closed row stays, so the period the officer held that ground is still on record.
 */
final class ReleaseAssignment
{
    public function release(Assignment $assignment, User $releasedBy): Assignment
    {
        if (! $releasedBy->supervises()) {
            throw new RuntimeException('Only a supervisor can release an assignment.');
        }

        if ($assignment->closed_at !== null) {
            throw new RuntimeException('That assignment is already closed.');
        }

        if (! $assignment->status->isOpen()) {
            throw new RuntimeException(
                'Submitted work cannot be released. Review it, or return it to the officer.',
            );
        }

        return DB::transaction(function () use ($assignment): Assignment {
            $assignment->update([
                'status' => AssignmentStatus::Reassigned,
                'closed_at' => now(),
            ]);

            $assignment->gridCell?->update(['status' => GridCell::STATUS_UNASSIGNED]);

            return $assignment->fresh() ?? $assignment;
        });
    }
}
