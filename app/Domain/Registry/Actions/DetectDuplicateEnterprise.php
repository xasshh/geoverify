<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Registry\Models\Enterprise;
use Illuminate\Support\Facades\DB;

/**
 * Finds businesses that look like the one just captured.
 *
 * Proximity alone is useless in a market: fifty distinct traders sit within
 * twenty metres of each other. Name similarity alone is useless too, because
 * "Chidi Stores" appears in every state. Together they are a reasonable signal.
 *
 * This never merges or rejects anything. It records what it found so a supervisor
 * can decide, because two shops genuinely called the same thing next door to each
 * other is a real situation and not a mistake.
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
        /** @var list<object{id: int, trading_name: string, distance_m: float, sim: float}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT e.id,
                   e.trading_name,
                   ST_Distance(s.centroid, mine.centroid) AS distance_m,
                   similarity(e.trading_name, ?)          AS sim
              FROM enterprises e
              JOIN structures s ON s.id = e.structure_id
              JOIN structures mine ON mine.id = ?
             WHERE e.id <> ?
               AND ST_DWithin(s.centroid, mine.centroid, ?)
               AND similarity(e.trading_name, ?) > ?
             ORDER BY sim DESC, distance_m
             LIMIT 5
        SQL, [
            $enterprise->trading_name,
            $enterprise->structure_id,
            $enterprise->id,
            self::RADIUS_M,
            $enterprise->trading_name,
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
