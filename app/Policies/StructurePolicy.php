<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Field\Enums\AssignmentStatus;
use App\Domain\Field\Models\Assignment;
use App\Domain\Registry\Models\Structure;
use App\Models\User;

/**
 * An officer captures only inside ground that is currently theirs.
 *
 * Without this an officer could post captures for any cell in the mandate, and a
 * register where the assignment record and the capture record disagree about who
 * was standing where is a register that cannot survive being challenged.
 */
final class StructurePolicy
{
    public function view(User $user, Structure $structure): bool
    {
        return $user->supervises() || $structure->captured_by === $user->id;
    }

    public function capture(User $user, Structure $structure): bool
    {
        return $user->supervises() || $this->holdsCell($user, $structure->grid_cell_id);
    }

    public function captureInCell(User $user, int $gridCellId): bool
    {
        return $user->supervises() || $this->holdsCell($user, $gridCellId);
    }

    private function holdsCell(User $user, int $gridCellId): bool
    {
        if (! $user->capturesInTheField()) {
            return false;
        }

        return Assignment::query()
            ->where('grid_cell_id', $gridCellId)
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->whereIn('status', [
                AssignmentStatus::Assigned->value,
                AssignmentStatus::InProgress->value,
                AssignmentStatus::Returned->value,
            ])
            ->exists();
    }
}
