<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Field\Actions\ReadLiveOperations;
use App\Domain\Field\Models\Assignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the work is happening, while it happens.
 *
 * A supervisor with eight officers out cannot ring each of them, and the thing
 * they need to know is not "is everyone busy" but "is anyone stuck, lost or
 * somewhere they should not be". So this leads with the officers who have gone
 * quiet and the captures that are already contested, rather than with a total.
 */
final class LiveOperationsController
{
    public function index(Request $request, ReadLiveOperations $live): Response
    {
        Gate::authorize('viewAny', Assignment::class);

        $areaId = $request->integer('area') ?: null;

        return Inertia::render('console/Live', [
            'live' => $live($areaId),
            'bounds' => $this->bounds($areaId),
            'areas' => CoverageArea::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (CoverageArea $area): array => [
                    'id' => $area->id,
                    'name' => $area->name,
                ])->all(),
            'filters' => ['area' => $areaId],
        ]);
    }

    /**
     * The ground the map opens on, read from the mandates themselves.
     *
     * Taken from the contracted boundaries rather than from wherever the
     * officers happen to be, so an empty morning still opens on the work and a
     * single officer in the wrong place does not drag the view to them.
     *
     * @return array{float, float, float, float}
     */
    public function bounds(?int $areaId): array
    {
        $where = $areaId === null ? '' : 'where id = ?';

        $box = DB::selectOne(<<<SQL
            select st_xmin(extent) as minx, st_ymin(extent) as miny,
                   st_xmax(extent) as maxx, st_ymax(extent) as maxy
              from (select st_extent(boundary) as extent from coverage_areas {$where}) e
        SQL, $areaId === null ? [] : [$areaId]);

        return [
            (float) ($box->minx ?? 0.0),
            (float) ($box->miny ?? 0.0),
            (float) ($box->maxx ?? 0.0),
            (float) ($box->maxy ?? 0.0),
        ];
    }

    /**
     * The same reading as JSON, for the page to poll.
     *
     * Polled rather than pushed. A console open on a desk for eight hours is not
     * worth a websocket, and a supervisor who reloads and sees the same numbers
     * is better served than one whose connection quietly died an hour ago.
     */
    public function feed(Request $request, ReadLiveOperations $live): JsonResponse
    {
        Gate::authorize('viewAny', Assignment::class);

        return JsonResponse::fromJsonString(
            json_encode($live($request->integer('area') ?: null), JSON_THROW_ON_ERROR),
        );
    }
}
