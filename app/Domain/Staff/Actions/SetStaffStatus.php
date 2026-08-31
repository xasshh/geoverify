<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Suspends somebody, or lets them back in.
 *
 * Never deletion. An officer's captures have to stay attributable after they
 * leave, so the account is closed rather than removed and everything they
 * recorded keeps its author.
 *
 * Suspension does not revoke their handsets. Those are separate on purpose: a
 * person suspended pending a conversation is not the same as a phone in a
 * stranger's hands, and collapsing the two means every suspension needs a
 * re-enrolment afterwards.
 */
final class SetStaffStatus
{
    public function __invoke(User $admin, User $person, string $status, string $reason): User
    {
        if (! $admin->administers()) {
            throw new RuntimeException('Only an administrator can suspend or reinstate somebody.');
        }

        if (! in_array($status, [User::STATUS_ACTIVE, User::STATUS_SUSPENDED], true)) {
            throw new RuntimeException('That is not a status a person can hold.');
        }

        if ($person->id === $admin->id) {
            throw new RuntimeException('You cannot suspend yourself.');
        }

        if ($person->status === $status) {
            throw new RuntimeException('That is already their status.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('Say why. Somebody will read this back later.');
        }

        return DB::transaction(function () use ($admin, $person, $status, $reason): User {
            $was = $person->status;

            $person->update(['status' => $status]);

            // A suspended person keeps no session. Without this they stay signed
            // in on whatever they were using until the cookie expires, which is
            // the whole window a suspension is meant to close.
            if ($status === User::STATUS_SUSPENDED) {
                $person->tokens()->delete();
            }

            VerificationEvent::record($person, 'staff.status_changed', $admin, [
                'from' => $was,
                'to' => $status,
                'reason' => $reason,
            ]);

            return $person->refresh();
        });
    }
}
