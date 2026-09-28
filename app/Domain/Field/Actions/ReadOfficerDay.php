<?php

declare(strict_types=1);

namespace App\Domain\Field\Actions;

use App\Domain\Field\Enums\AssignmentStatus;
use App\Domain\Field\Models\Assignment;
use App\Domain\Field\Models\FieldMessage;
use App\Domain\Registry\Models\Structure;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An officer's day, as the Today screen shows it: the campaign they are on,
 * how long they have been out, what they have captured, which cells are done,
 * what came back to fix, and the table of today's records.
 *
 * Read-only, and everything is counted from the tables the field platform
 * already writes. The daily target is configuration, because nothing in the
 * register says how many a day is reasonable on this ground.
 */
final class ReadOfficerDay
{
    public function __construct(private readonly FieldMessaging $messages) {}

    /** @return array<string, mixed> */
    public function __invoke(User $officer): array
    {
        $today = Carbon::today(config('app.timezone'));
        $supervisor = $this->messages->supervisorOf($officer);
        $captures = $this->captures($officer, $today);
        $target = (int) config('geoverify.field.daily_capture_target', 25);

        return [
            'officer' => ['name' => $officer->name, 'staffRef' => $officer->staff_ref],
            'supervisor' => $supervisor === null ? null : [
                'name' => $supervisor->name,
                'staffRef' => $supervisor->staff_ref,
                'phone' => $supervisor->phone,
            ],
            'campaign' => $this->campaign($officer, $today),
            'shiftStartedAt' => $this->shiftStart($officer, $today),
            'capturesToday' => count($captures),
            'target' => $target,
            'pace' => $this->pace(count($captures), $target, $this->shiftStart($officer, $today)),
            'cells' => $this->cells($officer),
            'returned' => $this->returned($officer),
            'unread' => FieldMessage::query()
                ->where('officer_id', $officer->id)
                ->where('direction', FieldMessage::TO_OFFICER)
                ->whereNull('read_at')
                ->count(),
            'captures' => $captures,
            'jobs' => $this->jobs($officer),
        ];
    }

    /**
     * Inspections and site visits given to this officer and not yet reported.
     *
     * @return list<array<string, mixed>>
     */
    private function jobs(User $officer): array
    {
        return array_map(static fn (object $r): array => [
            'id' => (int) $r->id,
            'kind' => (string) $r->kind,
            'business' => (string) $r->trading_name,
            'orderRef' => (string) $r->reference,
            'requestedFor' => $r->requested_for === null ? null : Carbon::parse((string) $r->requested_for)->toIso8601String(),
        ], DB::select(<<<'SQL'
            SELECT i.id, i.kind, i.requested_for, e.trading_name, po.reference
              FROM inspections i
              JOIN purchase_orders po ON po.id = i.purchase_order_id
              JOIN enterprises e ON e.id = po.enterprise_id
             WHERE i.agent_id = ? AND i.status = 'assigned'
             ORDER BY i.requested_for NULLS FIRST, i.assigned_at
        SQL, [$officer->id]));
    }

    /** @return array{name: string, code: string, area: string|null, day: int|null, days: int|null}|null */
    private function campaign(User $officer, Carbon $today): ?array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT c.name, c.code, c.starts_on, c.ends_on, ca.name AS area
              FROM assignments a
              JOIN grid_cells g ON g.id = a.grid_cell_id
              JOIN coverage_areas ca ON ca.id = g.coverage_area_id
              LEFT JOIN campaigns c ON c.id = ca.campaign_id
             WHERE a.user_id = ? AND a.closed_at IS NULL
             ORDER BY a.assigned_at DESC
             LIMIT 1
        SQL, [$officer->id]);

        if ($row === null) {
            return null;
        }

        if ($row->name === null) {
            // Cells on a mandate no campaign has claimed: the area is still
            // worth saying.
            return ['name' => (string) $row->area, 'code' => '', 'area' => null, 'day' => null, 'days' => null];
        }

        $starts = $row->starts_on === null ? null : Carbon::parse((string) $row->starts_on);
        $ends = $row->ends_on === null ? null : Carbon::parse((string) $row->ends_on);

        return [
            'name' => (string) $row->name,
            'code' => (string) $row->code,
            'area' => (string) $row->area,
            'day' => $starts === null || $today->lt($starts) ? null : (int) $starts->diffInDays($today) + 1,
            'days' => $starts === null || $ends === null ? null : (int) $starts->diffInDays($ends) + 1,
        ];
    }

    private function shiftStart(User $officer, Carbon $today): ?string
    {
        $at = DB::table('field_sessions')
            ->where('user_id', $officer->id)
            ->where('started_at', '>=', $today)
            ->min('started_at');

        return $at === null ? null : Carbon::parse((string) $at)->toIso8601String();
    }

    /**
     * "7 to go, on pace to finish by 3:40 pm": the rate so far, carried
     * forward. Null until there is a rate to carry.
     *
     * @return array{remaining: int, finishAt: string|null}
     */
    private function pace(int $done, int $target, ?string $shiftStart): array
    {
        $remaining = max(0, $target - $done);

        if ($done === 0 || $remaining === 0 || $shiftStart === null) {
            return ['remaining' => $remaining, 'finishAt' => null];
        }

        $elapsed = max(1, Carbon::parse($shiftStart)->diffInSeconds(now()));
        $finish = now()->addSeconds((int) round($elapsed / $done * $remaining));

        return ['remaining' => $remaining, 'finishAt' => $finish->toIso8601String()];
    }

    /** @return array{total: int, complete: int, inProgress: int, notStarted: int, next: list<array<string, mixed>>} */
    private function cells(User $officer): array
    {
        $assignments = Assignment::query()
            ->with('gridCell:id,h3_index,footprint_count,structures_captured,coverage_area_id')
            ->where('user_id', $officer->id)
            ->whereNotIn('status', [AssignmentStatus::Reassigned->value])
            // A paid inspection is a job, listed on its own, not a cell to sweep.
            ->where('kind', '<>', Assignment::KIND_INSPECTION)
            ->where(static fn ($q) => $q->whereNull('closed_at')->orWhereIn('status', [AssignmentStatus::Submitted->value, AssignmentStatus::Accepted->value]))
            ->orderByRaw('due_on nulls last')
            ->orderBy('assigned_at')
            ->get();

        $complete = $assignments->filter(static fn (Assignment $a): bool => in_array($a->status, [AssignmentStatus::Submitted, AssignmentStatus::Accepted], true))->count();
        $inProgress = $assignments->filter(static fn (Assignment $a): bool => $a->status === AssignmentStatus::InProgress || ($a->status === AssignmentStatus::Assigned && (int) ($a->gridCell->structures_captured ?? 0) > 0))->count();

        return [
            'total' => $assignments->count(),
            'complete' => $complete,
            'inProgress' => $inProgress,
            'notStarted' => max(0, $assignments->count() - $complete - $inProgress),
            'next' => $assignments
                ->filter(static fn (Assignment $a): bool => $a->closed_at === null && $a->status->isOpen())
                ->take(3)
                ->map(fn (Assignment $a): array => [
                    'assignmentId' => $a->id,
                    'h3' => $a->gridCell?->h3() ?? '',
                    'captured' => (int) ($a->gridCell->structures_captured ?? 0),
                    'footprints' => (int) ($a->gridCell->footprint_count ?? 0),
                    'started' => (int) ($a->gridCell->structures_captured ?? 0) > 0,
                    'coverageAreaId' => (int) ($a->gridCell->coverage_area_id ?? 0),
                    'centre' => $this->centre($a->grid_cell_id),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array{0: float, 1: float} */
    private function centre(int $gridCellId): array
    {
        $row = DB::selectOne('SELECT ST_X(centroid::geometry) AS lon, ST_Y(centroid::geometry) AS lat FROM grid_cells WHERE id = ?', [$gridCellId]);

        return [(float) ($row->lon ?? 0), (float) ($row->lat ?? 0)];
    }

    /** @return array{count: int, oldestAt: string|null} */
    private function returned(User $officer): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT count(*) AS n, min(o.updated_at) AS oldest
              FROM structure_observations o
             WHERE o.captured_by = ? AND o.status = ?
               AND NOT EXISTS (
                   SELECT 1 FROM structure_observations newer
                    WHERE newer.structure_id = o.structure_id
                      AND newer.observed_at > o.observed_at)
        SQL, [$officer->id, Structure::STATUS_REJECTED]);

        return [
            'count' => (int) $row->n,
            'oldestAt' => $row->oldest === null ? null : Carbon::parse((string) $row->oldest)->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function captures(User $officer, Carbon $today): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT o.id, o.observed_at, o.capture_accuracy_m, o.status, o.structure_type, o.assignment_id,
                   s.h3_index::h3index::text AS cell,
                   e.trading_name, ic.name AS sector,
                   (SELECT count(*) FROM media m
                     WHERE m.mediable_type = ? AND m.mediable_id = s.id
                       AND m.captured_by = o.captured_by) AS photos
              FROM structure_observations o
              JOIN structures s ON s.id = o.structure_id
              LEFT JOIN LATERAL (
                   SELECT trading_name, sector_code FROM enterprises
                    WHERE structure_id = s.id ORDER BY id DESC LIMIT 1) e ON true
              LEFT JOIN isic_classes ic ON ic.code = e.sector_code
             WHERE o.captured_by = ? AND o.observed_at >= ?
             ORDER BY o.observed_at DESC
             LIMIT 100
        SQL, [(new Structure)->getMorphClass(), $officer->id, $today]);

        return array_map(static fn (object $r): array => [
            'id' => (int) $r->id,
            'ref' => FieldMessaging::recordRef((int) $r->id),
            'business' => $r->trading_name,
            'type' => implode(' · ', array_filter([str_replace('_', ' ', (string) $r->structure_type), $r->sector])),
            'cell' => (string) $r->cell,
            'at' => Carbon::parse((string) $r->observed_at)->toIso8601String(),
            'accuracyM' => $r->capture_accuracy_m === null ? null : (float) $r->capture_accuracy_m,
            'photos' => (int) $r->photos,
            'status' => (string) $r->status,
            'assignmentId' => $r->assignment_id === null ? null : (int) $r->assignment_id,
        ], $rows);
    }
}
