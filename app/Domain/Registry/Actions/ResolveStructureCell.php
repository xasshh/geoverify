<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Registry\Models\Structure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The grid cell a building stands in, for sending somebody to it.
 *
 * A building an officer swept carries its cell. One that registered itself
 * carries none by design (structures_origin_shape), because no mandate put it
 * there; its cell is found spatially, in PostGIS. Outside every mandate there
 * is no cell, and so nobody deployed to send.
 */
final class ResolveStructureCell
{
    public function __invoke(Structure $structure): int
    {
        if ($structure->grid_cell_id !== null) {
            return $structure->grid_cell_id;
        }

        $id = DB::selectOne(
            'SELECT g.id FROM grid_cells g JOIN structures s ON ST_Contains(g.boundary, s.centroid::geometry) WHERE s.id = ? LIMIT 1',
            [$structure->id],
        )?->id;

        if ($id === null) {
            throw new RuntimeException('This business is outside every area we have agents in, so nobody can be sent yet.');
        }

        return (int) $id;
    }
}
