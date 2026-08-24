<?php

declare(strict_types=1);

namespace App\Http\Controllers\Field;

use App\Domain\Coverage\Models\MapPack;
use App\Domain\Field\Models\Assignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Serves the offline map pack to a handset.
 *
 * Two endpoints and no more: what is available, and the bytes. The client is
 * told the size before it commits, because an officer on a metered connection
 * deciding whether to spend 67 MB is making a real decision and the app has no
 * business making it for them.
 */
final class MapPackController
{
    /** What the handset should be holding for the mandates it is assigned to. */
    public function index(Request $request): JsonResponse
    {
        $areaIds = Assignment::query()
            ->open()
            ->where('assignments.user_id', $request->user()?->id)
            ->join('grid_cells', 'grid_cells.id', '=', 'assignments.grid_cell_id')
            ->distinct()
            ->pluck('grid_cells.coverage_area_id');

        $packs = MapPack::query()
            ->whereIn('coverage_area_id', $areaIds)
            ->current()
            ->with('coverageArea:id,name')
            ->get();

        return response()->json([
            'packs' => $packs->map(static fn (MapPack $pack): array => [
                'id' => $pack->id,
                'coverageAreaId' => $pack->coverage_area_id,
                'mandate' => $pack->coverageArea->name,
                'bytes' => $pack->bytes,
                'megabytes' => $pack->megabytes(),
                'secondsAt2Mbps' => $pack->secondsAt2Mbps(),
                'checksum' => $pack->checksum,
                'minZoom' => $pack->min_zoom,
                'maxZoom' => $pack->max_zoom,
                'layers' => $pack->layer_counts,
                'bounds' => [
                    (float) $pack->west, (float) $pack->south,
                    (float) $pack->east, (float) $pack->north,
                ],
                'builtAt' => $pack->built_at->toIso8601String(),
                'url' => route('api.field.packs.show', $pack),
            ])->values()->all(),
        ]);
    }

    /**
     * The archive itself.
     *
     * Served as a file response rather than a stream so the framework answers
     * Range requests: a 67 MB download that dies at 60 MB on a handset must
     * resume, not start again. That is the whole reason this is not a
     * Storage::download.
     */
    public function show(Request $request, MapPack $pack): BinaryFileResponse|RedirectResponse
    {
        abort_unless($this->officerHolds($request, $pack), 403);

        $disk = Storage::disk('media');

        // Object storage serves the bytes itself. Nothing is gained by pulling
        // 67 MB through PHP on the way past, and a redirect keeps Range working.
        if (config('filesystems.disks.media.driver') !== 'local') {
            return redirect()->away($disk->temporaryUrl($pack->path, now()->addHour()));
        }

        return response()->file($disk->path($pack->path), [
            'Content-Type' => 'application/vnd.pmtiles',
            'ETag' => '"'.$pack->checksum.'"',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /**
     * An officer may fetch a pack for a mandate they are working, and no other.
     *
     * Checked here rather than assumed from the listing: the download URL is a
     * plain GET that outlives the page that produced it.
     */
    private function officerHolds(Request $request, MapPack $pack): bool
    {
        return Assignment::query()
            ->open()
            ->where('assignments.user_id', $request->user()?->id)
            ->join('grid_cells', 'grid_cells.id', '=', 'assignments.grid_cell_id')
            ->where('grid_cells.coverage_area_id', $pack->coverage_area_id)
            ->exists();
    }
}
