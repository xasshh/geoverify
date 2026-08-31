<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Enums\ReviewDecision;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An admin answers the question a supervisor would not answer alone.
 *
 * Upholding sends the capture back to the officer; dismissing enters it into the
 * register. Either way the state change goes through ReviewObservation, so the
 * projection onto the structure and the cell's accepted count are maintained in
 * one place rather than in two that drift.
 *
 * A second event is appended on top of the ordinary one. Both are true and
 * neither is redundant: the capture was accepted or returned, and separately, an
 * escalation about an officer's honesty was ruled on. An auditor reading back
 * needs to see the second, and "observation.accepted" alone does not say it.
 */
final class ResolveEscalation
{
    public const UPHELD = 'upheld';

    public const DISMISSED = 'dismissed';

    public function __construct(private readonly ReviewObservation $review) {}

    public function __invoke(
        StructureObservation $observation,
        User $admin,
        string $outcome,
        string $note,
    ): StructureObservation {
        if (! $admin->administers()) {
            throw new RuntimeException('Only an administrator can resolve an escalation.');
        }

        if ($observation->status !== Structure::STATUS_FLAGGED) {
            throw new RuntimeException('That capture is not escalated.');
        }

        if ($observation->captured_by === $admin->id) {
            throw new RuntimeException('You cannot rule on an escalation against your own capture.');
        }

        if (! in_array($outcome, [self::UPHELD, self::DISMISSED], true)) {
            throw new RuntimeException('An escalation is either upheld or dismissed.');
        }

        if (trim($note) === '') {
            throw new RuntimeException('Say what you found. This is a ruling about a person.');
        }

        // The supervisor who raised it does not get to rule on it, even where
        // one person holds both roles. Escalation exists so that a judgement
        // about an officer's honesty is made by somebody other than the person
        // who suspected it, and a shared account is the easiest way to lose that.
        if ($this->raisedBy($observation) === $admin->id) {
            throw new RuntimeException('You raised this escalation, so somebody else has to rule on it.');
        }

        return DB::transaction(function () use ($observation, $admin, $outcome, $note): StructureObservation {
            $decision = $outcome === self::UPHELD
                ? ReviewDecision::Return
                : ReviewDecision::Accept;

            $resolved = ($this->review)(
                $observation,
                $decision,
                $admin,
                $outcome === self::UPHELD ? $note : null,
                $note,
            );

            VerificationEvent::record($resolved, 'observation.escalation_resolved', $admin, [
                'outcome' => $outcome,
                'note' => $note,
                'landed_on' => $decision->observationStatus(),
            ]);

            return $resolved;
        });
    }

    /** Who raised the escalation, from the log that recorded it. */
    private function raisedBy(StructureObservation $observation): ?int
    {
        $actor = DB::scalar(
            'select actor_id from verification_events
              where subject_type = ? and subject_id = ? and event = ?
              order by occurred_at desc limit 1',
            [$observation->getMorphClass(), $observation->id, 'observation.escalated'],
        );

        return $actor === null ? null : (int) $actor;
    }
}
