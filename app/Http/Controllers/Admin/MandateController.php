<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Coverage\Actions\CreateCoverageArea;
use App\Domain\Coverage\Actions\GenerateGrid;
use App\Domain\Coverage\Models\AdminBoundary;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                areas.default_h3_resolution, areas.created_at,
                round((ST_Area(areas.boundary::geography) / 1e6)::numeric, 1) as area_km2,
                (select count(*) from grid_cells where coverage_area_id = areas.id) as cells,
                (select coalesce(sum(footprint_count), 0) from grid_cells where coverage_area_id = areas.id) as footprints
            from coverage_areas areas
            order by areas.created_at desc
        SQL);

        return Inertia::render('admin/Mandates', [
            'mandates' => array_map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'client' => (string) $row->client_name,
                'lgaCode' => (string) $row->lga_code,
                'contractRef' => $row->contract_ref,
                'resolution' => (int) $row->default_h3_resolution,
                'areaKm2' => (float) $row->area_km2,
                'cells' => (int) $row->cells,
                'footprints' => (int) $row->footprints,
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

    private function admin(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
