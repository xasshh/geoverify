<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Models\User;

/**
 * Who may review a capture.
 *
 * An officer may not decide their own work, and the check is here rather than in
 * the controller because it is the kind of rule that has to hold everywhere the
 * decision can be reached, including from a console a supervisor is signed into
 * on someone else's behalf.
 */
final class StructureObservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->supervises();
    }

    public function view(User $user, StructureObservation $observation): bool
    {
        return $user->supervises() || $observation->captured_by === $user->id;
    }

    public function review(User $user, StructureObservation $observation): bool
    {
        if (! $user->supervises() || ! $user->isActive()) {
            return false;
        }

        // Nobody decides their own capture, whatever role they hold.
        if ($observation->captured_by === $user->id) {
            return false;
        }

        // A decision already taken is not retaken. Re-enumeration creates a new
        // observation, which is the route back for work that has to change.
        return $observation->status === Structure::STATUS_SUBMITTED;
    }
}
