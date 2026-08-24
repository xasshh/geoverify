<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Enums\ReviewDecision;
use App\Domain\Verification\Models\ObservationSignal;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A supervisor's decision on one capture.
 *
 * Nothing is deleted and nothing is overwritten. The observation's status moves,
 * the structure's projection follows it, and the decision itself is appended to
 * verification_events with the score and the flags as they stood at the moment
 * it was taken. That last part matters: rescoring later must never be able to
 * make a supervisor's decision look better or worse informed than it was.
 */
final class ReviewObservation
{
    public function __invoke(
        StructureObservation $observation,
        ReviewDecision $decision,
        User $supervisor,
        ?string $reason = null,
        ?string $note = null,
    ): StructureObservation {
        if (! $supervisor->supervises()) {
            throw new RuntimeException('Only a supervisor can decide a capture.');
        }

        if ($decision->requiresReason() && ($reason === null || trim($reason) === '')) {
            throw new RuntimeException('Sending work back needs a reason the officer can act on.');
        }

        if ($observation->status === $decision->observationStatus()) {
            throw new RuntimeException('That capture has already been '.mb_strtolower($decision->label()).'.');
        }

        return DB::transaction(function () use ($observation, $decision, $supervisor, $reason, $note): StructureObservation {
            $was = $observation->status;

            $observation->update(['status' => $decision->observationStatus()]);

            // The structure carries the projection of its latest decided
            // observation, so a coverage view never has to re-derive it.
            $this->projectOntoStructure($observation, $decision);
            $this->refreshCellAcceptance($observation);

            VerificationEvent::record($observation, $decision->event(), $supervisor, array_filter([
                'from' => $was,
                'to' => $decision->observationStatus(),
                // The score as it stood when the decision was taken, not as it
                // stands now. A rescore must not rewrite what was known.
                'confidence_score' => $observation->confidence_score,
                'flags' => $this->flagsAtDecision($observation),
                'reason' => $reason,
                'note' => $note,
            ], static fn (mixed $value): bool => $value !== null && $value !== []));

            return $observation->refresh();
        });
    }

    /**
     * Moves the structure to match, but only when this is its latest observation.
     *
     * A supervisor working through a backlog can decide an older observation
     * after a newer one has already landed. Letting that overwrite the
     * projection would make the register describe March using a decision taken
     * about February.
     */
    private function projectOntoStructure(StructureObservation $observation, ReviewDecision $decision): void
    {
        $latest = StructureObservation::query()
            ->where('structure_id', $observation->structure_id)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null || $latest->id !== $observation->id) {
            return;
        }

        Structure::query()
            ->whereKey($observation->structure_id)
            ->update([
                'status' => $decision->observationStatus(),
                'confidence_score' => $observation->confidence_score,
            ]);
    }

    /**
     * How much of the cell has been accepted, recomputed from the table.
     *
     * Recomputed rather than incremented, like the captured count beside it, so
     * a decision taken twice or a return that follows an acceptance cannot
     * leave the coverage map claiming ground the register does not hold.
     */
    private function refreshCellAcceptance(StructureObservation $observation): void
    {
        DB::statement(<<<'SQL'
            update grid_cells g
               set structures_accepted = c.n,
                   updated_at = now()
              from (
                  select count(*) as n
                    from structures
                   where grid_cell_id = (select grid_cell_id from structures where id = ?)
                     and status = ?
              ) c
             where g.id = (select grid_cell_id from structures where id = ?)
        SQL, [
            $observation->structure_id,
            Structure::STATUS_ACCEPTED,
            $observation->structure_id,
        ]);
    }

    /**
     * The flags as they read at the moment of the decision.
     *
     * @return list<array{signal: string, verdict: string, message: string}>
     */
    private function flagsAtDecision(StructureObservation $observation): array
    {
        return ObservationSignal::query()
            ->where('structure_observation_id', $observation->id)
            ->flagged()
            ->orderByDesc('deduction')
            ->get()
            ->map(static fn (ObservationSignal $signal): array => [
                'signal' => $signal->signal,
                'verdict' => $signal->verdict->value,
                'message' => $signal->message,
            ])
            ->all();
    }
}
