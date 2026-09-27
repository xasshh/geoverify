<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Field\Actions\FieldMessaging;
use App\Domain\Field\Actions\ReadLiveOperations;
use App\Domain\Verification\Actions\BuildReviewQueue;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team today, to the supervisor board: four counts, the live map, the officers
 * with where they are and how many they have done, the QA queue with Return
 * and Accept in reach, and "Send to officers".
 *
 * Composed from what the console already reads. The map and its data are the
 * Live page's, unchanged; the queue is the Review page's; the team is the
 * officers holding cells this supervisor handed out.
 */
final class TeamTodayController
{
    /** A last fix older than this, during a shift, reads as no signal. */
    private const NO_SIGNAL_MINUTES = 30;

    public function __construct(
        private readonly FieldMessaging $messages,
        private readonly ReadLiveOperations $live,
        private readonly BuildReviewQueue $queue,
    ) {}

    public function __invoke(Request $request): Response
    {
        $supervisor = $request->user();
        abort_unless($supervisor instanceof User, 403);

        $team = $this->messages->teamOf($supervisor);
        $live = ($this->live)();
        $liveById = collect($live['officers'])->keyBy('officerId');
        $today = Carbon::today(config('app.timezone'));

        $capturesToday = DB::table('structure_observations')
            ->whereIn('captured_by', $team->pluck('id'))
            ->where('observed_at', '>=', $today)
            ->selectRaw('captured_by, count(*) AS n')
            ->groupBy('captured_by')
            ->pluck('n', 'captured_by');

        $unread = DB::table('field_messages')
            ->whereIn('officer_id', $team->pluck('id'))
            ->where('direction', 'from_officer')
            ->whereNull('read_at')
            ->selectRaw('officer_id, count(*) AS n')
            ->groupBy('officer_id')
            ->pluck('n', 'officer_id');

        $officers = $team->map(function (User $officer) use ($liveById, $capturesToday, $unread): array {
            /** @var array<string, mixed>|null $seen */
            $seen = $liveById->get($officer->id);
            $minutes = $seen === null || $seen['lastSeenAt'] === null ? null : (int) Carbon::parse((string) $seen['lastSeenAt'])->diffInMinutes(now());

            $state = match (true) {
                $seen === null => 'off',
                // Signed off for the day: not missing, just finished.
                $seen['endedAt'] !== null && ! (bool) $seen['active'] => 'finished',
                $minutes !== null && $minutes >= self::NO_SIGNAL_MINUTES => 'no_signal',
                (bool) $seen['active'] => 'on_site',
                default => 'idle',
            };

            return [
                'id' => $officer->id,
                'name' => $officer->name,
                'staffRef' => $officer->staff_ref,
                'phone' => $officer->phone,
                'state' => $state,
                'minutes' => $minutes,
                'cell' => $seen['h3'] ?? null,
                'capturesToday' => (int) ($capturesToday[$officer->id] ?? 0),
                'unread' => (int) ($unread[$officer->id] ?? 0),
            ];
        })->values()->all();

        $queue = ($this->queue)(8);
        /** @var list<array{observedAt: string|null, flags: list<array{signal: string}>}> $rows */
        $rows = $queue['rows'];
        $oldest = collect($rows)->min('observedAt');
        $inField = collect($officers)->whereIn('state', ['on_site', 'idle'])->count();
        $noSignal = collect($officers)->where('state', 'no_signal');
        $gpsFlagged = collect($rows)->filter(static fn (array $row): bool => collect($row['flags'])->contains(static fn (array $f): bool => str_contains((string) $f['signal'], 'accuracy')))->count();

        return Inertia::render('console/TeamToday', [
            'campaign' => $this->campaign($supervisor),
            'stats' => [
                'inField' => $inField,
                'teamSize' => count($officers),
                'noSignal' => $noSignal->count(),
                'capturesToday' => (int) collect($officers)->sum('capturesToday'),
                'target' => count($officers) * (int) config('geoverify.field.daily_capture_target', 25),
                'awaitingQa' => (int) $queue['awaiting'],
                'oldestQaAt' => $oldest,
                'gpsFlagged' => $gpsFlagged,
                'alerts' => $noSignal->map(static fn (array $o): string => 'No signal: '.($o['staffRef'] ?? $o['name']))->values()->all(),
            ],
            'officers' => $officers,
            'queue' => $queue['rows'],
            'live' => $live,
            // The Live page's own framing: the contracted ground, not wherever
            // the officers happen to be.
            'bounds' => app(LiveOperationsController::class)->bounds(null),
            'cells' => DB::table('assignments')
                ->join('grid_cells', 'grid_cells.id', '=', 'assignments.grid_cell_id')
                ->where('assignments.assigned_by', $supervisor->id)
                ->whereNull('assignments.closed_at')
                ->selectRaw('grid_cells.h3_index::h3index::text AS h3')
                ->pluck('h3')
                ->all(),
        ]);
    }

    /** The brief of the campaign this supervisor's cells belong to. */
    public function brief(Request $request): Response
    {
        $supervisor = $request->user();
        abort_unless($supervisor instanceof User, 403);

        $c = DB::selectOne(<<<'SQL'
            SELECT c.name, c.code, c.about, c.objective, c.starts_on, c.ends_on, c.target_record_count
              FROM assignments a
              JOIN grid_cells g ON g.id = a.grid_cell_id
              JOIN coverage_areas ca ON ca.id = g.coverage_area_id
              JOIN campaigns c ON c.id = ca.campaign_id
             WHERE a.assigned_by = ? AND a.closed_at IS NULL
             GROUP BY c.id
             ORDER BY count(*) DESC
             LIMIT 1
        SQL, [$supervisor->id]);

        return Inertia::render('console/Brief', [
            'brief' => $c === null ? null : [
                'name' => (string) $c->name,
                'code' => (string) $c->code,
                'about' => $c->about,
                'objective' => $c->objective,
                'startsOn' => $c->starts_on,
                'endsOn' => $c->ends_on,
                'target' => $c->target_record_count === null ? null : (int) $c->target_record_count,
            ],
        ]);
    }

    /** @return array{name: string, area: string|null}|null */
    private function campaign(User $supervisor): ?array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT c.name, string_agg(DISTINCT ca.name, ' & ') AS areas
              FROM assignments a
              JOIN grid_cells g ON g.id = a.grid_cell_id
              JOIN coverage_areas ca ON ca.id = g.coverage_area_id
              LEFT JOIN campaigns c ON c.id = ca.campaign_id
             WHERE a.assigned_by = ? AND a.closed_at IS NULL
             GROUP BY c.name
             ORDER BY count(*) DESC
             LIMIT 1
        SQL, [$supervisor->id]);

        return $row === null ? null : ['name' => (string) ($row->name ?? $row->areas), 'area' => $row->name === null ? null : (string) $row->areas];
    }
}
