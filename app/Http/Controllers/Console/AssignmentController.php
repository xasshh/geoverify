<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Actions\AssignCells;
use App\Domain\Field\Actions\ReleaseAssignment;
use App\Domain\Field\Models\Assignment;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Assigning ground to people, and seeing who holds what.
 */
final class AssignmentController
{
    public function index(Request $request, CoverageArea $coverageArea): Response
    {
        Gate::authorize('assign', Assignment::class);

        return Inertia::render('console/Assignments', [
            'area' => [
                'id' => $coverageArea->id,
                'name' => $coverageArea->name,
                'client' => $coverageArea->client_name,
            ],
            'officers' => $this->officerWorkload(),
            'assignments' => $this->openAssignments($coverageArea),
            'unassignedCount' => DB::scalar(
                'select count(*) from grid_cells g
                  where g.coverage_area_id = ?
                    and not exists (select 1 from assignments a
                                     where a.grid_cell_id = g.id and a.closed_at is null)',
                [$coverageArea->id],
            ),
        ]);
    }

    /**
     * Assigns the busiest unassigned cells first.
     *
     * A supervisor handing out a day's work wants the ground with buildings on it,
     * not an arbitrary slice of a mandate that is mostly empty scrub.
     */
    public function store(Request $request, CoverageArea $coverageArea, AssignCells $assigner): RedirectResponse
    {
        Gate::authorize('assign', Assignment::class);

        $validated = $request->validate([
            'officer_id' => ['required', 'integer', 'exists:users,id'],
            'count' => ['required_without:grid_cell_ids', 'integer', 'min:1', 'max:500'],
            'grid_cell_ids' => ['sometimes', 'array', 'max:500'],
            'grid_cell_ids.*' => ['integer'],
            'due_on' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $officer = User::query()->findOrFail($validated['officer_id']);

        /** @var list<int> $cellIds */
        $cellIds = isset($validated['grid_cell_ids']) && $validated['grid_cell_ids'] !== []
            ? array_map(intval(...), $validated['grid_cell_ids'])
            : $this->busiestUnassignedCells($coverageArea, (int) ($validated['count'] ?? 0));

        try {
            $result = $assigner->assign(
                $cellIds,
                $officer,
                $request->user(),
                isset($validated['due_on']) ? Carbon::parse((string) $validated['due_on']) : null,
            );
        } catch (Throwable $e) {
            return back()->withErrors(['officer_id' => $e->getMessage()]);
        }

        $message = "Assigned {$result['assigned']} cells to {$officer->name}.";

        if ($result['reassigned'] > 0) {
            $message .= " {$result['reassigned']} were taken from another officer.";
        }

        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} were left alone: already theirs, or awaiting review.";
        }

        return back()->with('status', $message);
    }

    public function destroy(Request $request, Assignment $assignment, ReleaseAssignment $releaser): RedirectResponse
    {
        Gate::authorize('release', $assignment);

        try {
            $releaser->release($assignment, $request->user());
        } catch (Throwable $e) {
            return back()->withErrors(['assignment' => $e->getMessage()]);
        }

        return back()->with('status', 'Cell released and back in the unassigned pool.');
    }

    /**
     * @return list<int>
     */
    private function busiestUnassignedCells(CoverageArea $area, int $count): array
    {
        if ($count < 1) {
            return [];
        }

        /** @var list<object{id: int}> $rows */
        $rows = DB::select(
            'select g.id from grid_cells g
              where g.coverage_area_id = ?
                and g.footprint_count > 0
                and not exists (select 1 from assignments a
                                 where a.grid_cell_id = g.id and a.closed_at is null)
              order by g.footprint_count desc, g.id
              limit ?',
            [$area->id, $count],
        );

        return array_map(static fn (object $row): int => (int) $row->id, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function officerWorkload(): array
    {
        /** @var list<object{id: int, name: string, staff_ref: string|null, open: int, overdue: int, footprints: int}> $rows */
        $rows = DB::select(
            "select u.id, u.name, u.staff_ref,
                    count(a.id) filter (where a.closed_at is null and a.status in ('assigned','in_progress','returned')) as open,
                    count(a.id) filter (where a.closed_at is null and a.due_on < current_date
                                          and a.status in ('assigned','in_progress','returned')) as overdue,
                    coalesce(sum(g.footprint_count) filter (where a.closed_at is null
                        and a.status in ('assigned','in_progress','returned')), 0) as footprints
               from users u
               left join assignments a on a.user_id = u.id
               left join grid_cells g on g.id = a.grid_cell_id
              where u.role = ? and u.status = ?
              group by u.id, u.name, u.staff_ref
              order by u.name",
            [Role::Officer->value, User::STATUS_ACTIVE],
        );

        return array_map(static fn (object $r): array => [
            'id' => (int) $r->id,
            'name' => $r->name,
            'staffRef' => $r->staff_ref,
            'open' => (int) $r->open,
            'overdue' => (int) $r->overdue,
            'footprints' => (int) $r->footprints,
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openAssignments(CoverageArea $area): array
    {
        $assignments = Assignment::query()
            ->with(['officer:id,name,staff_ref', 'gridCell:id,h3_index,footprint_count,structures_captured'])
            ->whereNull('closed_at')
            ->whereHas('gridCell', fn ($q) => $q->where('coverage_area_id', $area->id))
            ->orderByRaw('due_on nulls last')
            ->limit(300)
            ->get();

        return $assignments->map(static fn (Assignment $a): array => [
            'id' => $a->id,
            'officer' => $a->officer instanceof User ? $a->officer->name : 'Unknown',
            'staffRef' => $a->officer instanceof User ? $a->officer->staff_ref : null,
            'h3' => $a->gridCell instanceof GridCell ? $a->gridCell->h3() : '',
            'footprints' => (int) ($a->gridCell->footprint_count ?? 0),
            'captured' => (int) ($a->gridCell->structures_captured ?? 0),
            'status' => $a->status->value,
            'statusLabel' => $a->status->label(),
            'dueOn' => $a->due_on?->toDateString(),
            'overdue' => $a->isOverdue(),
            'assignedAt' => $a->assigned_at?->toDateTimeString(),
        ])->all();
    }
}
