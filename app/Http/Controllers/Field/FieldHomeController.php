<?php

declare(strict_types=1);

namespace App\Http\Controllers\Field;

use App\Domain\Field\Actions\ReadOfficerDay;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The officer's screens around the map: Today, My records, the supervisor
 * inbox, the campaign brief and Sync & device. The map itself is the capture
 * screen, unchanged, and "My map" goes straight to it.
 *
 * Every screen shares the officer's day summary so the sidebar counts and the
 * header read the same wherever the officer is.
 */
final class FieldHomeController
{
    public function __construct(private readonly ReadOfficerDay $day) {}

    public function today(Request $request): Response
    {
        return Inertia::render('field/Today', ['day' => ($this->day)($this->officer($request))]);
    }

    public function records(Request $request): Response
    {
        $officer = $this->officer($request);

        return Inertia::render('field/Records', [
            'day' => ($this->day)($officer),
            'returned' => app(AssignmentBoardController::class)->returnedFor($officer),
        ]);
    }

    public function inbox(Request $request): Response
    {
        return Inertia::render('field/Inbox', ['day' => ($this->day)($this->officer($request))]);
    }

    public function brief(Request $request): Response
    {
        $officer = $this->officer($request);

        $campaign = DB::selectOne(<<<'SQL'
            SELECT c.name, c.code, c.about, c.objective, c.starts_on, c.ends_on, c.target_record_count
              FROM assignments a
              JOIN grid_cells g ON g.id = a.grid_cell_id
              JOIN coverage_areas ca ON ca.id = g.coverage_area_id
              JOIN campaigns c ON c.id = ca.campaign_id
             WHERE a.user_id = ? AND a.closed_at IS NULL
             ORDER BY a.assigned_at DESC
             LIMIT 1
        SQL, [$officer->id]);

        return Inertia::render('field/Brief', [
            'day' => ($this->day)($officer),
            'brief' => $campaign === null ? null : [
                'name' => (string) $campaign->name,
                'code' => (string) $campaign->code,
                'about' => $campaign->about,
                'objective' => $campaign->objective,
                'startsOn' => $campaign->starts_on,
                'endsOn' => $campaign->ends_on,
                'target' => $campaign->target_record_count === null ? null : (int) $campaign->target_record_count,
            ],
        ]);
    }

    public function device(Request $request): Response
    {
        $officer = $this->officer($request);

        $mandates = DB::table('assignments')
            ->join('grid_cells', 'grid_cells.id', '=', 'assignments.grid_cell_id')
            ->join('coverage_areas', 'coverage_areas.id', '=', 'grid_cells.coverage_area_id')
            ->where('assignments.user_id', $officer->id)
            ->whereNull('assignments.closed_at')
            ->distinct()
            ->get(['coverage_areas.id as coverage_area_id', 'coverage_areas.name as mandate'])
            ->map(static fn (object $row): array => ['coverageAreaId' => (int) $row->coverage_area_id, 'mandate' => (string) $row->mandate])
            ->values()
            ->all();

        return Inertia::render('field/Device', ['day' => ($this->day)($officer), 'mandates' => $mandates]);
    }

    /** "My map": the capture map for the cell the officer should be in next. */
    public function map(Request $request): RedirectResponse
    {
        $next = ($this->day)($this->officer($request))['cells']['next'][0] ?? null;

        return $next === null
            ? redirect()->route('field.index')->with('status', 'No cells are assigned to you yet.')
            : redirect()->route('field.capture', $next['assignmentId']);
    }

    private function officer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
