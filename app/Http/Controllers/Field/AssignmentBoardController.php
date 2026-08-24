<?php

declare(strict_types=1);

namespace App\Http\Controllers\Field;

use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Models\Assignment;
use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the officer sees when they open the app: their work, and nothing else.
 *
 * Scoped by the authenticated user rather than by a request parameter, so there
 * is no identifier to tamper with.
 */
final class AssignmentBoardController
{
    public function index(Request $request): Response
    {
        $officer = $request->user();

        $assignments = Assignment::query()
            ->with(['gridCell:id,h3_index,footprint_count,structures_captured,coverage_area_id',
                'gridCell.coverageArea:id,name,client_name'])
            ->where('user_id', $officer->id)
            ->whereNull('closed_at')
            ->orderByRaw('due_on nulls last')
            ->get();

        $returned = $this->returnedCaptures($officer, $assignments->pluck('id')->all());

        return Inertia::render('field/Assignments', [
            'officer' => [
                'name' => $officer->name,
                'staffRef' => $officer->staff_ref,
            ],
            'assignments' => $assignments->map(static fn (Assignment $a): array => [
                'id' => $a->id,
                'h3' => $a->gridCell instanceof GridCell ? $a->gridCell->h3() : '',
                'mandate' => $a->gridCell?->coverageArea->name ?? '',
                'coverageAreaId' => (int) ($a->gridCell->coverage_area_id ?? 0),
                'footprints' => (int) ($a->gridCell->footprint_count ?? 0),
                'captured' => (int) ($a->gridCell->structures_captured ?? 0),
                'status' => $a->status->value,
                'statusLabel' => $a->status->label(),
                'dueOn' => $a->due_on?->toDateString(),
                'overdue' => $a->isOverdue(),
                'returnReason' => $a->return_reason,
                // Work a supervisor sent back, with the reason they gave. A
                // return that the officer cannot see is not a return.
                'returnedCaptures' => $returned[$a->id] ?? [],
            ])->all(),
        ]);
    }

    /**
     * Captures a supervisor sent back, grouped by the assignment they belong to.
     *
     * The reason is read from the log rather than copied onto the observation,
     * because the log is where the decision actually lives and a second copy is
     * a second thing that can drift from it.
     *
     * @param  list<int>  $assignmentIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function returnedCaptures(User $officer, array $assignmentIds): array
    {
        if ($assignmentIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));

        $rows = DB::select(<<<SQL
            select
                observations.id,
                observations.assignment_id,
                observations.structure_type,
                decision.evidence ->> 'reason' as reason,
                decision.occurred_at as returned_at
            from structure_observations observations
            join lateral (
                select evidence, occurred_at
                from verification_events
                where subject_type = ?
                  and subject_id = observations.id
                  and event = 'observation.returned'
                order by id desc
                limit 1
            ) decision on true
            where observations.captured_by = ?
              and observations.status = ?
              and observations.assignment_id in ({$placeholders})
            order by decision.occurred_at desc
        SQL, [
            (new StructureObservation)->getMorphClass(),
            $officer->id,
            Structure::STATUS_REJECTED,
            ...$assignmentIds,
        ]);

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->assignment_id][] = [
                'id' => (int) $row->id,
                'structureType' => (string) $row->structure_type,
                'reason' => $row->reason,
                'returnedAt' => $row->returned_at,
            ];
        }

        return $grouped;
    }
}
