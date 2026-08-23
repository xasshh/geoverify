<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Coverage\Models\AdminBoundary;
use Illuminate\Support\Facades\DB;

/**
 * Decides which ward, LGA and state a captured point falls in.
 *
 * The server decides this, always, by ST_Contains against loaded boundaries. A
 * client supplied ward name is never trusted and is not even accepted: a handset
 * can claim anything, and a register whose geography comes from the device being
 * audited is not evidence.
 *
 * Runs entirely in PostgreSQL.
 */
final class ResolveAdminHierarchy
{
    /**
     * @return array{ward_id: int|null, lga_id: int|null, state_id: int|null}
     */
    public function forPoint(float $longitude, float $latitude): array
    {
        /** @var object{ward_id: int|null, lga_id: int|null, state_id: int|null}|null $row */
        $row = DB::selectOne(<<<'SQL'
            WITH p AS (SELECT ST_SetSRID(ST_Point(?, ?), 4326) AS geom)
            SELECT
                (SELECT b.id FROM admin_boundaries b, p
                  WHERE b.level = 'ward' AND ST_Contains(b.boundary, p.geom) LIMIT 1) AS ward_id,
                (SELECT b.id FROM admin_boundaries b, p
                  WHERE b.level = 'lga'  AND ST_Contains(b.boundary, p.geom) LIMIT 1) AS lga_id,
                (SELECT b.id FROM admin_boundaries b, p
                  WHERE b.level = 'state' AND ST_Contains(b.boundary, p.geom) LIMIT 1) AS state_id
        SQL, [$longitude, $latitude]);

        $ward = $row?->ward_id === null ? null : (int) $row->ward_id;
        $lga = $row?->lga_id === null ? null : (int) $row->lga_id;
        $state = $row?->state_id === null ? null : (int) $row->state_id;

        // A ward is contained by an LGA which is contained by a state. If the
        // point landed in a ward but no LGA was found directly, the ward's own
        // parents are the better answer than a null, and they came from the same
        // containment pass at load time.
        if ($ward !== null && ($lga === null || $state === null)) {
            $parents = $this->parentsOf($ward);
            $lga ??= $parents['lga_id'];
            $state ??= $parents['state_id'];
        }

        return ['ward_id' => $ward, 'lga_id' => $lga, 'state_id' => $state];
    }

    /**
     * @return array{lga_id: int|null, state_id: int|null}
     */
    private function parentsOf(int $wardId): array
    {
        /** @var object{lga_id: int|null, state_id: int|null}|null $row */
        $row = DB::selectOne(
            'SELECT lga.id AS lga_id, state.id AS state_id
               FROM admin_boundaries ward
               LEFT JOIN admin_boundaries lga   ON lga.id = ward.parent_id
               LEFT JOIN admin_boundaries state ON state.id = lga.parent_id
              WHERE ward.id = ?',
            [$wardId],
        );

        return [
            'lga_id' => $row?->lga_id === null ? null : (int) $row->lga_id,
            'state_id' => $row?->state_id === null ? null : (int) $row->state_id,
        ];
    }

    /**
     * Human readable, for showing an officer where the server placed their
     * capture. Names come from the boundaries, never from the device.
     *
     * @param  array{ward_id: int|null, lga_id: int|null, state_id: int|null}  $resolved
     * @return array{ward: string|null, lga: string|null, state: string|null}
     */
    public function describe(array $resolved): array
    {
        $ids = array_filter([$resolved['ward_id'], $resolved['lga_id'], $resolved['state_id']]);

        if ($ids === []) {
            return ['ward' => null, 'lga' => null, 'state' => null];
        }

        $names = AdminBoundary::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id');

        return [
            'ward' => $resolved['ward_id'] === null ? null : $names->get($resolved['ward_id']),
            'lga' => $resolved['lga_id'] === null ? null : $names->get($resolved['lga_id']),
            'state' => $resolved['state_id'] === null ? null : $names->get($resolved['state_id']),
        ];
    }
}
