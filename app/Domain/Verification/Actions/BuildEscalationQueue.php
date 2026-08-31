<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Captures a supervisor would not decide alone.
 *
 * Escalation was always written to the log and never read back out of it, so a
 * flagged capture left the review queue and stopped existing anywhere a person
 * looks. This is the other half: the queue the button was always promising.
 *
 * Oldest first, because the cost here falls on the officer. A flagged capture is
 * an unanswered question about somebody's honesty, and leaving it open is worse
 * than deciding it either way.
 */
final class BuildEscalationQueue
{
    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(): array
    {
        $rows = DB::select(<<<'SQL'
            select
                observations.id,
                observations.observed_at,
                observations.confidence_score,
                observations.structure_type,
                structures.id as structure_id,
                officer.id as officer_id,
                officer.name as officer_name,
                ward.name as ward,
                -- The escalation itself: who raised it, when, and what they
                -- wrote. Read from the log rather than from a column, because
                -- the log is where the decision actually lives.
                event.occurred_at as escalated_at,
                event.actor_label as escalated_by,
                event.evidence ->> 'reason' as reason,
                (
                    select count(*) from structure_observations peers
                     where peers.captured_by = observations.captured_by
                       and peers.status = ?
                ) as officer_flagged_total
            from structure_observations observations
            join structures on structures.id = observations.structure_id
            join users officer on officer.id = observations.captured_by
            left join admin_boundaries ward on ward.id = structures.ward_id
            left join lateral (
                select occurred_at, actor_label, evidence
                  from verification_events
                 where subject_type = ?
                   and subject_id = observations.id
                   and event = ?
                 order by occurred_at desc
                 limit 1
            ) event on true
            where observations.status = ?
            order by observations.observed_at
        SQL, [
            Structure::STATUS_FLAGGED,
            (new StructureObservation)->getMorphClass(),
            'observation.escalated',
            Structure::STATUS_FLAGGED,
        ]);

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'structureId' => (int) $row->structure_id,
            'structureType' => (string) $row->structure_type,
            'observedAt' => Carbon::parse((string) $row->observed_at)->toIso8601String(),
            'score' => $row->confidence_score === null ? null : (int) $row->confidence_score,
            'ward' => $row->ward,
            'officer' => [
                'id' => (int) $row->officer_id,
                'name' => (string) $row->officer_name,
                'flaggedTotal' => (int) $row->officer_flagged_total,
            ],
            'escalatedAt' => $row->escalated_at === null
                ? null
                : Carbon::parse((string) $row->escalated_at)->toIso8601String(),
            'escalatedBy' => $row->escalated_by,
            'reason' => $row->reason,
        ], $rows);
    }
}
