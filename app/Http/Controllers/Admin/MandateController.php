<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Coverage\Actions\CreateCoverageArea;
use App\Domain\Coverage\Actions\CreateCoverageAreaFromBoundary;
use App\Domain\Coverage\Actions\GenerateGrid;
use App\Domain\Coverage\Models\AdminBoundary;
use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Imagery\Actions\RequestSatelliteBasemap;
use App\Domain\Imagery\Models\BasemapLayer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Mandates, without shell access.
 *
 * Creating one used to mean four commands on the server, so in practice one
 * person could do it. The two steps that need a person's judgement are here; the
 * two that need large files staged on the server are not, and the screen says
 * so rather than pretending a mandate is finished when it is not.
 */
final class MandateController
{
    public function index(): Response
    {
        $areas = DB::select(<<<'SQL'
            select
                areas.id, areas.name, areas.client_name, areas.lga_code, areas.contract_ref,
                areas.default_h3_resolution, areas.created_at, areas.boundary_source,
                round((ST_Area(areas.boundary::geography) / 1e6)::numeric, 1) as area_km2,
                (select count(*) from grid_cells where coverage_area_id = areas.id) as cells,
                (select coalesce(sum(footprint_count), 0) from grid_cells where coverage_area_id = areas.id) as footprints
            from coverage_areas areas
            order by areas.created_at desc
        SQL);

        // The newest imagery request per mandate, whatever its state, so the
        // screen can show a build in progress, a failure, or what is current.
        $imagery = BasemapLayer::query()
            ->whereIn('id', BasemapLayer::query()->selectRaw('max(id)')->groupBy('coverage_area_id'))
            ->get()
            ->mapWithKeys(static fn (BasemapLayer $layer): array => [$layer->coverage_area_id => [
                'status' => $layer->status,
                'progress' => $layer->progress,
                'stage' => $layer->stage,
                'error' => $layer->error,
                'captured' => $layer->capturedLabel(),
                'cloudPct' => $layer->cloud_pct === null ? null : (float) $layer->cloud_pct,
                'megabytes' => $layer->bytes === null ? null : $layer->megabytes(),
                'maxZoom' => $layer->max_zoom,
            ]])
            ->all();

        return Inertia::render('admin/Mandates', [
            'mandates' => array_map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'client' => (string) $row->client_name,
                'lgaCode' => $row->lga_code,
                'boundarySource' => (string) $row->boundary_source,
                'contractRef' => $row->contract_ref,
                'resolution' => (int) $row->default_h3_resolution,
                'areaKm2' => (float) $row->area_km2,
                'cells' => (int) $row->cells,
                'footprints' => (int) $row->footprints,
                'imagery' => $imagery[(int) $row->id] ?? null,
            ], $areas),

            // Only LGAs that are actually loaded. Offering the full 774 when
            // none of their boundaries are present would let somebody create a
            // mandate over ground this database has never seen.
            'lgas' => AdminBoundary::query()
                ->where('level', AdminBoundary::LEVEL_LGA)
                ->orderBy('name')
                ->get(['code', 'name'])
                ->map(static fn (AdminBoundary $lga): array => [
                    'code' => $lga->code,
                    'name' => $lga->name,
                ])->all(),
        ]);
    }

    public function store(Request $request, CreateCoverageArea $create, GenerateGrid $grid): RedirectResponse
    {
        $data = $request->validate([
            'lgaCode' => ['required', 'string', 'max:32'],
            'client' => ['required', 'string', 'max:160'],
            'name' => ['nullable', 'string', 'max:160'],
            'contractRef' => ['nullable', 'string', 'max:64'],
            'resolution' => ['required', 'integer', 'min:7', 'max:11'],
        ]);

        try {
            $area = $create(
                lgaCode: $data['lgaCode'],
                client: $data['client'],
                name: $data['name'] ?? null,
                contractRef: $data['contractRef'] ?? null,
                resolution: $data['resolution'],
                actor: $this->admin(),
            );

            // Generated here rather than left for later. A mandate with no grid
            // has no work list, so it is not a mandate yet, and leaving the two
            // apart is how one ends up created and forgotten.
            $grid->generate($area, $data['resolution']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['lgaCode' => $e->getMessage()]);
        }

        return back()->with(
            'status',
            "{$area->name} created and tiled. Ingest footprints and build its map pack on the server.",
        );
    }

    /** A mandate from a boundary file the client sent, tiled like any other. */
    public function storeFromBoundary(Request $request, CreateCoverageAreaFromBoundary $create, GenerateGrid $grid): RedirectResponse
    {
        $data = $request->validate([
            'boundary' => ['required', 'file', 'max:51200'],
            'client' => ['required', 'string', 'max:160'],
            'name' => ['required', 'string', 'max:160'],
            'contractRef' => ['nullable', 'string', 'max:64'],
            'resolution' => ['required', 'integer', 'min:7', 'max:11'],
        ]);

        /** @var UploadedFile $file */
        $file = $data['boundary'];

        $area = $create(
            file: $file,
            name: $data['name'],
            client: $data['client'],
            contractRef: $data['contractRef'] ?? null,
            resolution: $data['resolution'],
            actor: $this->admin(),
        );

        $tiled = $grid->generate($area, $data['resolution']);

        $note = $tiled['claimed_elsewhere'] > 0
            ? " {$tiled['claimed_elsewhere']} cells overlap another mandate and stay with it."
            : '';

        return back()->with('status', "{$area->name} created from the boundary file and tiled into {$tiled['total']} cells.{$note}");
    }

    /** Builds (or rebuilds) the mandate's satellite imagery, on the imagery queue. */
    public function requestImagery(CoverageArea $area, RequestSatelliteBasemap $request): RedirectResponse
    {
        $request($area, $this->admin());

        return back()->with('status', "Building satellite imagery for {$area->name}. This takes a few minutes; the status updates here.");
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
