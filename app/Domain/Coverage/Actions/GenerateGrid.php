<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Actions;

use App\Domain\Coverage\Models\CoverageArea;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cuts a coverage area into H3 cells: the units of work officers are assigned.
 *
 * Every step runs in PostgreSQL. Nothing about a cell's geometry is computed in
 * PHP, and no coordinate is round-tripped through it.
 *
 * The operation is idempotent and resumable. Re-running over the same area adds
 * only cells that are missing, so an interrupted generation over a large mandate
 * is repaired by running it again.
 */
final class GenerateGrid
{
    /**
     * Cells whose centre is inside the boundary. Leaves the mandate edge uncovered.
     */
    public const CONTAINMENT_CENTER = 'center';

    /**
     * Any cell touching the boundary. The default, because an enumeration mandate
     * has to cover its own edge: businesses cluster along LGA boundaries, and a
     * cell dropped there is a business never visited.
     */
    public const CONTAINMENT_OVERLAPPING = 'overlapping';

    /** Only cells wholly inside. Useful for reporting, never for assignment. */
    public const CONTAINMENT_FULL = 'full';

    private const MODES = [
        self::CONTAINMENT_CENTER,
        self::CONTAINMENT_OVERLAPPING,
        self::CONTAINMENT_FULL,
    ];

    /**
     * @return array{created: int, existing: int, claimed_elsewhere: int, total: int}
     */
    public function generate(
        CoverageArea $area,
        int $resolution,
        string $containment = self::CONTAINMENT_OVERLAPPING,
    ): array {
        if (! in_array($containment, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown containment mode: {$containment}");
        }

        if ($resolution < 0 || $resolution > 15) {
            throw new InvalidArgumentException("H3 resolution must be 0 to 15, got {$resolution}");
        }

        $before = $this->countFor($area);

        // parent_h3_index is the resolution-1 ancestor, which is how the console
        // rolls a coverage map up without re-tiling the whole mandate.
        $parentResolution = max(0, $resolution - 1);

        DB::statement(<<<'SQL'
            INSERT INTO grid_cells (
                coverage_area_id, h3_index, h3_resolution, parent_h3_index,
                boundary, centroid, status, created_at, updated_at
            )
            SELECT
                ?,
                cell::bigint,
                ?,
                h3_cell_to_parent(cell, ?)::bigint,
                h3_cell_to_boundary_geometry(cell),
                h3_cell_to_geometry(cell)::geography,
                'unassigned',
                now(),
                now()
              FROM (
                    SELECT h3_polygon_to_cells_experimental(ca.boundary, ?, ?) AS cell
                      FROM coverage_areas ca
                     WHERE ca.id = ?
                   ) AS tiled
             ON CONFLICT (h3_index) DO NOTHING
        SQL, [$area->id, $resolution, $parentResolution, $resolution, $containment, $area->id]);

        $after = $this->countFor($area);
        $created = $after - $before;

        // How many cells the tiling produced but this area did not get, because the
        // unique index on h3_index means a cell belongs to exactly one mandate.
        // Silently leaving a hole in a second client's grid would be worse than
        // saying so.
        $tiled = $this->countTiled($area, $resolution, $containment);
        $claimedElsewhere = max(0, $tiled - $after);

        return [
            'created' => $created,
            'existing' => $before,
            'claimed_elsewhere' => $claimedElsewhere,
            'total' => $after,
        ];
    }

    private function countFor(CoverageArea $area): int
    {
        return (int) DB::scalar(
            'select count(*) from grid_cells where coverage_area_id = ?',
            [$area->id],
        );
    }

    private function countTiled(CoverageArea $area, int $resolution, string $containment): int
    {
        return (int) DB::scalar(
            'select count(*) from (
                select h3_polygon_to_cells_experimental(ca.boundary, ?, ?) as cell
                  from coverage_areas ca where ca.id = ?
             ) t',
            [$resolution, $containment, $area->id],
        );
    }
}
