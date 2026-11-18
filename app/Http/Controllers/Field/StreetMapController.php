<?php

declare(strict_types=1);

namespace App\Http\Controllers\Field;

use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Field\Models\Assignment;
use App\Domain\Imagery\StreetMaps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The offline street map, for the mandates an officer is working.
 *
 * Its own endpoints beside the packs and the imagery, never inside
 * /api/field/packs, whose answer a shipped handset depends on. A phone that
 * has never heard of this keeps working exactly as it did.
 */
final class StreetMapController
{
    public function index(Request $request, StreetMaps $streets): JsonResponse
    {
        $areas = CoverageArea::query()->whereIn('id', $this->areaIds($request))->orderBy('id')->get(['id', 'name']);
        $offered = [];

        foreach ($areas as $area) {
            $meta = $streets->meta($area->id);

            if ($meta === null) {
                continue;
            }

            $offered[] = [
                'coverageAreaId' => $area->id,
                'mandate' => $area->name,
                'bytes' => $meta['bytes'],
                'megabytes' => round($meta['bytes'] / 1_048_576, 1),
                'checksum' => $meta['checksum'],
                'bounds' => $meta['bounds'],
                'maxZoom' => $meta['maxZoom'],
                'url' => route('api.field.streets.show', $area),
            ];
        }

        return response()->json(['streets' => $offered]);
    }

    /** The archive, with Range support so a download resumes where it stopped. */
    public function show(Request $request, CoverageArea $coverageArea, StreetMaps $streets): BinaryFileResponse
    {
        abort_unless($this->areaIds($request)->contains($coverageArea->id), 403);

        $meta = $streets->meta($coverageArea->id);

        abort_if($meta === null, 404);

        return response()->file($streets->path($coverageArea->id), [
            'Content-Type' => 'application/vnd.pmtiles',
            'ETag' => '"'.$meta['checksum'].'"',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /**
     * The mandates this officer holds open cells in.
     *
     * @return Collection<int, int>
     */
    private function areaIds(Request $request): Collection
    {
        return Assignment::query()
            ->open()
            ->where('assignments.user_id', $request->user()?->id)
            ->join('grid_cells', 'grid_cells.id', '=', 'assignments.grid_cell_id')
            ->distinct()
            ->pluck('grid_cells.coverage_area_id')
            ->map(static fn (mixed $id): int => (int) $id);
    }
}
