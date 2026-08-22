<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Reports whether the spatial stack this system depends on is actually present.
 *
 * Every value here is read from the database, not from configuration, because the
 * question being answered is "can this server generate a grid", not "was it meant to".
 */
final class CheckSpatialStack
{
    /**
     * A small polygon inside Abuja Municipal (AMAC), FCT. Used only to prove that
     * cell generation runs; the real coverage boundary is loaded in M2.
     */
    private const TEST_POLYGON = 'POLYGON((7.44 9.03, 7.50 9.03, 7.50 9.08, 7.44 9.08, 7.44 9.03))';

    /**
     * @return array{postgis: string, h3: string, cellsOverAbuja: int}
     */
    public function __invoke(): array
    {
        return [
            'postgis' => $this->extensionVersion('postgis'),
            'h3' => $this->extensionVersion('h3'),
            'cellsOverAbuja' => $this->cellsOverTestPolygon(),
        ];
    }

    private function extensionVersion(string $name): string
    {
        $version = DB::scalar(
            'select extversion from pg_extension where extname = ?',
            [$name],
        );

        return is_string($version) ? $version : 'not installed';
    }

    /**
     * Counts H3 resolution 9 cells covering the test polygon. All spatial work stays
     * in PostgreSQL: this method issues one query and counts rows.
     */
    private function cellsOverTestPolygon(): int
    {
        $count = DB::scalar(
            'select count(*) from h3_polygon_to_cells(st_geomfromtext(?, 4326), 9)',
            [self::TEST_POLYGON],
        );

        return is_numeric($count) ? (int) $count : 0;
    }
}
