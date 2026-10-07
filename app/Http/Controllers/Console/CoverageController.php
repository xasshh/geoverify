<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Coverage\Actions\ReadRoadNetwork;
use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Imagery\Models\BasemapLayer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The coverage view: cells shaded by completion against the footprint denominator.
 *
 * Every geometry is serialised by PostGIS. No coordinate is assembled in PHP, and
 * nothing is projected or measured here.
 */
final class CoverageController
{
    /** Every mandate this console covers. The console's front door. */
    public function index(): Response
    {
        $areas = CoverageArea::query()
            ->withCount('gridCells')
            ->orderBy('name')
            ->get();

        return Inertia::render('console/CoverageIndex', [
            'areas' => $areas->map(fn (CoverageArea $area): array => [
                'id' => $area->id,
                'name' => $area->name,
                'client' => $area->client_name,
                'contractRef' => $area->contract_ref,
                'lgaCode' => $area->lga_code,
                'status' => $area->status,
                'cells' => (int) $area->grid_cells_count,
            ])->all(),
        ]);
    }

    public function show(CoverageArea $coverageArea): Response
    {
        return Inertia::render('console/Coverage', [
            'area' => [
                'id' => $coverageArea->id,
                'name' => $coverageArea->name,
                'client' => $coverageArea->client_name,
                'contractRef' => $coverageArea->contract_ref,
                'lgaCode' => $coverageArea->lga_code,
                'resolution' => $coverageArea->default_h3_resolution,
            ],
            'summary' => $this->summary($coverageArea),
            // The current satellite image, if one is built: shown under the
            // grid in the browser, from the same archive officers carry.
            'imagery' => ($layer = BasemapLayer::query()->where('coverage_area_id', $coverageArea->id)->current()->latest('built_at')->first()) === null ? null : [
                'url' => route('console.coverage.imagery', $coverageArea),
                'captured' => $layer->capturedLabel(),
                'maxZoom' => $layer->max_zoom,
                'licence' => $layer->licence_note,
            ],
        ]);
    }

    /**
     * Cells as GeoJSON, clipped to the requested viewport.
     *
     * A mandate the size of Abuja Municipal is 18,337 cells at resolution 9, so the
     * viewport filter is what keeps this view usable rather than a nicety.
     */
    public function cells(Request $request, CoverageArea $coverageArea): JsonResponse
    {
        $validated = $request->validate([
            'bbox' => ['nullable', 'string', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25000'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20000);
        $bbox = isset($validated['bbox'])
            ? array_map(floatval(...), explode(',', (string) $validated['bbox']))
            : null;

        $bindings = [$coverageArea->id];
        $clip = '';

        if ($bbox !== null && count($bbox) === 4) {
            $clip = 'AND g.boundary && ST_MakeEnvelope(?, ?, ?, ?, 4326)';
            array_push($bindings, $bbox[0], $bbox[1], $bbox[2], $bbox[3]);
        }

        $bindings[] = $limit;

        $geojson = DB::scalar(<<<SQL
            SELECT json_build_object(
                'type', 'FeatureCollection',
                'features', COALESCE(json_agg(feature), '[]'::json)
            )::text
            FROM (
                SELECT json_build_object(
                    'type', 'Feature',
                    'id', g.id,
                    'geometry', ST_AsGeoJSON(g.boundary, 5)::json,
                    'properties', json_build_object(
                        'h3', to_hex(g.h3_index),
                        'status', g.status,
                        'footprints', g.footprint_count,
                        'captured', g.structures_captured,
                        'accepted', g.structures_accepted,
                        'coverage', g.coverage_pct,
                        -- Verified against the same denominator as coverage, so
                        -- the two shadings are read on one scale.
                        'verified', CASE WHEN g.footprint_count = 0 THEN 0
                                         ELSE least(100, round(
                                             (g.structures_accepted::numeric / g.footprint_count) * 100, 2
                                         )) END
                    )
                ) AS feature
                  FROM grid_cells g
                 WHERE g.coverage_area_id = ?
                 {$clip}
                 ORDER BY g.footprint_count DESC
                 LIMIT ?
            ) features
        SQL, $bindings);

        return JsonResponse::fromJsonString(
            is_string($geojson) ? $geojson : '{"type":"FeatureCollection","features":[]}',
        );
    }

    /** The mandate outline, drawn as the hard edge of the work. */
    public function boundary(CoverageArea $coverageArea): JsonResponse
    {
        $geojson = DB::scalar(
            'select ST_AsGeoJSON(boundary, 5) from coverage_areas where id = ?',
            [$coverageArea->id],
        );

        return JsonResponse::fromJsonString(is_string($geojson) ? $geojson : '{}');
    }

    /**
     * The street network inside this mandate.
     *
     * Supervisors work from landmarks the same way officers do: a cell with a
     * low completion figure means something different when you can see it is
     * the far side of the expressway.
     */
    public function roads(Request $request, CoverageArea $coverageArea, ReadRoadNetwork $network): JsonResponse
    {
        $boundary = 'select boundary from coverage_areas where id = ?';
        $level = $request->string('detail')->toString() === ReadRoadNetwork::DETAIL
            ? ReadRoadNetwork::DETAIL
            : ReadRoadNetwork::OVERVIEW;

        return new JsonResponse([
            'roads' => $network->forBoundary($boundary, [$coverageArea->id], $level),
            'labels' => $network->labelsFor($boundary, [$coverageArea->id]),
        ]);
    }

    /**
     * @return array{cells: int, tiled: int, footprints: int, captured: int, accepted: int, cellsWithFootprints: int, busiest: int, medianPerCell: int, wards: int, bounds: array<int, float>}
     */
    private function summary(CoverageArea $area): array
    {
        /** @var object{cells: int, with_footprints: int, footprints: int, captured: int, accepted: int, busiest: int, median: float|null}|null $cells */
        $cells = DB::selectOne(<<<'SQL'
            SELECT count(*)                                                     AS cells,
                   count(*) FILTER (WHERE footprint_count > 0)                  AS with_footprints,
                   COALESCE(sum(footprint_count), 0)                            AS footprints,
                   COALESCE(sum(structures_captured), 0)                         AS captured,
                   COALESCE(sum(structures_accepted), 0)                         AS accepted,
                   COALESCE(max(footprint_count), 0)                            AS busiest,
                   percentile_cont(0.5) WITHIN GROUP (ORDER BY footprint_count)
                       FILTER (WHERE footprint_count > 0)                       AS median
              FROM grid_cells WHERE coverage_area_id = ?
        SQL, [$area->id]);

        /** @var object{minx: float, miny: float, maxx: float, maxy: float}|null $box */
        $box = DB::selectOne(
            'select ST_XMin(boundary) minx, ST_YMin(boundary) miny,
                    ST_XMax(boundary) maxx, ST_YMax(boundary) maxy
               from coverage_areas where id = ?',
            [$area->id],
        );

        $wards = (int) DB::scalar(
            'select count(*) from admin_boundaries w
              join coverage_areas c on c.id = ?
             where w.level = \'ward\' and ST_Intersects(w.boundary, c.boundary)',
            [$area->id],
        );

        return [
            'cells' => (int) ($cells->cells ?? 0),
            'tiled' => (int) ($cells->cells ?? 0),
            'footprints' => (int) ($cells->footprints ?? 0),
            // Visited and verified are different questions and the console has
            // to be able to answer both. A mandate that is 90 per cent captured
            // and 20 per cent accepted is not a mandate that is nearly done.
            'captured' => (int) ($cells->captured ?? 0),
            'accepted' => (int) ($cells->accepted ?? 0),
            'cellsWithFootprints' => (int) ($cells->with_footprints ?? 0),
            'busiest' => (int) ($cells->busiest ?? 0),
            'medianPerCell' => (int) round((float) ($cells->median ?? 0)),
            'wards' => $wards,
            'bounds' => [
                (float) ($box->minx ?? 0), (float) ($box->miny ?? 0),
                (float) ($box->maxx ?? 0), (float) ($box->maxy ?? 0),
            ],
        ];
    }

    /**
     * The mandate's current satellite archive, for the console map.
     *
     * Served whole with Range support, so the browser reads only the tiles in
     * view rather than the archive.
     */
    public function imagery(CoverageArea $coverageArea): BinaryFileResponse|RedirectResponse
    {
        $layer = BasemapLayer::query()
            ->where('coverage_area_id', $coverageArea->id)
            ->current()
            ->latest('built_at')
            ->firstOrFail();

        $disk = Storage::disk('media');

        if (config('filesystems.disks.media.driver') !== 'local') {
            return redirect()->away($disk->temporaryUrl((string) $layer->path, now()->addHour()));
        }

        return response()->file($disk->path((string) $layer->path), [
            'Content-Type' => 'application/vnd.pmtiles',
            'ETag' => '"'.$layer->checksum.'"',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
