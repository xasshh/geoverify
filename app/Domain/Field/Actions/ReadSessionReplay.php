<?php

declare(strict_types=1);

namespace App\Domain\Field\Actions;

use App\Domain\Field\Models\FieldSession;
use Illuminate\Support\Facades\DB;

/**
 * One officer's session, as a supervisor replays it: every recorded position
 * with its time, and each capture where and when it was made.
 *
 * Asked for one session at a time rather than carried in the live feed, which
 * is polled every few seconds and would otherwise send the whole day each time.
 * Positions are thinned in SQL to a number a browser draws without effort; the
 * first and last are always kept, so the replay starts and ends where the day
 * did. Times are seconds since the epoch, read in the application timezone the
 * columns are written in.
 */
final class ReadSessionReplay
{
    /** Enough to scrub a whole day smoothly. */
    private const POINTS = 1500;

    /**
     * @return array{
     *     sessionId: int,
     *     officer: string,
     *     points: list<array{0: float, 1: float, 2: int, 3: float|null, 4: bool}>,
     *     captures: list<array{0: float, 1: float, 2: int, 3: int|null}>
     * }
     */
    public function __invoke(FieldSession $session): array
    {
        $zone = (string) config('app.timezone');

        $points = DB::select(<<<'SQL'
            select lon, lat, at, accuracy, mock
              from (
                  select round(st_x(point)::numeric, 6)::float8 as lon,
                         round(st_y(point)::numeric, 6)::float8 as lat,
                         extract(epoch from (recorded_at at time zone ?))::bigint as at,
                         accuracy_m::float8 as accuracy,
                         is_mock as mock,
                         row_number() over (order by recorded_at) as n,
                         count(*) over () as total
                    from position_fixes
                   where field_session_id = ? and point is not null
              ) numbered
             where total <= ? or n = 1 or n = total or n % ceil(total::numeric / ?)::int = 0
             order by at
        SQL, [$zone, $session->id, self::POINTS, self::POINTS]);

        $captures = DB::select(<<<'SQL'
            select round(st_x(structures.centroid::geometry)::numeric, 6)::float8 as lon,
                   round(st_y(structures.centroid::geometry)::numeric, 6)::float8 as lat,
                   extract(epoch from (observations.observed_at at time zone ?))::bigint as at,
                   observations.confidence_score as confidence
              from structure_observations observations
              join structures on structures.id = observations.structure_id
             where observations.field_session_id = ? and structures.centroid is not null
             order by observations.observed_at
        SQL, [$zone, $session->id]);

        return [
            'sessionId' => $session->id,
            'officer' => (string) $session->officer?->name,
            'points' => array_map(static fn (object $row): array => [
                (float) $row->lon,
                (float) $row->lat,
                (int) $row->at,
                $row->accuracy === null ? null : round((float) $row->accuracy, 1),
                (bool) $row->mock,
            ], $points),
            'captures' => array_map(static fn (object $row): array => [
                (float) $row->lon,
                (float) $row->lat,
                (int) $row->at,
                $row->confidence === null ? null : (int) $row->confidence,
            ], $captures),
        ];
    }
}
