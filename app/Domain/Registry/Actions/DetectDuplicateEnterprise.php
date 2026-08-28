<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Registry\Models\Enterprise;
use Illuminate\Support\Facades\DB;

/**
 * Finds businesses that look like the one in front of us.
 *
 * Proximity alone is useless in a market: fifty distinct traders sit within
 * twenty metres of each other. Name similarity alone is useless too, because
 * "Chidi Stores" appears in every state. Together they are a reasonable signal.
 *
 * This never merges or rejects anything. It reports what it found so somebody
 * can decide, because two shops genuinely called the same thing next door to
 * each other is a real situation and not a mistake.
 *
 * Two callers, one rule. An officer's capture is checked after the fact, so a
 * supervisor sees the collision at review; a self-registration is checked
 * before anything is written, so the person can be offered the existing listing
 * instead of creating a second one. The check itself must be identical, because
 * a duplicate the field platform would flag and the portal would wave through
 * is a duplicate that exists.
 */
final class DetectDuplicateEnterprise
{
    private const RADIUS_M = 40;

    private const NAME_SIMILARITY = 0.55;

    /**
     * @return list<array{enterprise_id: int, trading_name: string, distance_m: float, similarity: float}>
     */
    public function near(Enterprise $enterprise): array
    {
        $point = DB::selectOne(
            'SELECT ST_Y(centroid::geometry) AS lat, ST_X(centroid::geometry) AS lng
               FROM structures WHERE id = ?',
            [$enterprise->structure_id],
        );

        if ($point === null) {
            return [];
        }

        return $this->nearPoint(
            (float) $point->lng,
            (float) $point->lat,
            $enterprise->trading_name,
            $enterprise->id,
        );
    }

    /**
     * The same question, asked before there is a record to ask it about.
     *
     * @param  int|null  $excluding  an enterprise that is itself the subject, so does not count as its own duplicate
     * @return list<array{enterprise_id: int, trading_name: string, distance_m: float, similarity: float}>
     */
    public function nearPoint(float $longitude, float $latitude, string $tradingName, ?int $excluding = null): array
    {
        /** @var list<object{id: int, trading_name: string, distance_m: float, sim: float}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT e.id,
                   e.trading_name,
                   ST_Distance(s.centroid, mine) AS distance_m,
                   similarity(e.trading_name, ?) AS sim
              FROM enterprises e
              JOIN structures s ON s.id = e.structure_id
             CROSS JOIN LATERAL (
                SELECT ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography AS mine
             ) point
             -- Cast, because Postgres cannot infer a type for a bare
             -- parameter that only ever appears beside IS NULL, and refuses
             -- the statement with "indeterminate datatype" rather than
             -- guessing.
             WHERE (?::bigint IS NULL OR e.id <> ?::bigint)
               AND s.status <> 'rejected'
               AND ST_DWithin(s.centroid, mine, ?)
               AND similarity(e.trading_name, ?) > ?
             ORDER BY sim DESC, distance_m
             LIMIT 5
        SQL, [
            $tradingName,
            $longitude,
            $latitude,
            $excluding,
            $excluding,
            self::RADIUS_M,
            $tradingName,
            self::NAME_SIMILARITY,
        ]);

        return array_map(static fn (object $r): array => [
            'enterprise_id' => (int) $r->id,
            'trading_name' => $r->trading_name,
            'distance_m' => round((float) $r->distance_m, 1),
            'similarity' => round((float) $r->sim, 3),
        ], $rows);
    }
}
