<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Field\Models\Assignment;
use App\Models\User;

/**
 * An officer sees their own work and nothing else. A supervisor sees all of it.
 *
 * This is enforced here rather than in a query filter so that forgetting the
 * filter somewhere cannot quietly widen what an officer can read.
 */
final class AssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, Assignment $assignment): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $user->supervises() || $assignment->user_id === $user->id;
    }

    public function assign(User $user): bool
    {
        return $user->supervises();
    }

    public function release(User $user, Assignment $assignment): bool
    {
        return $user->supervises() && $assignment->closed_at === null;
    }

    /** Only the officer holding it, and only while it is still theirs. */
    public function start(User $user, Assignment $assignment): bool
    {
        return $user->capturesInTheField()
            && $assignment->user_id === $user->id
            && $assignment->closed_at === null
            && $assignment->status->isOpen();
    }
}
