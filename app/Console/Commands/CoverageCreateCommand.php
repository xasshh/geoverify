<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Coverage\Models\AdminBoundary;
use App\Domain\Coverage\Models\CoverageArea;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates a mandate from a loaded administrative boundary.
 *
 * The boundary is copied from admin_boundaries rather than referenced, because a
 * mandate is contractual: if a later data release moves the LGA line, the ground
 * a client contracted for does not silently move with it.
 */
final class CoverageCreateCommand extends Command
{
    protected $signature = 'geoverify:coverage-create
        {--lga-code= : OCHA pcode of the LGA, for example NG015002}
        {--client= : Client name}
        {--name= : Mandate name, defaults to the LGA name}
        {--contract-ref= : Client contract reference}
        {--accuracy-threshold=15 : Mandate GPS accuracy threshold in metres}
        {--resolution=9 : Default H3 resolution for this mandate}';

    protected $description = 'Create a coverage area from a loaded LGA boundary';

    public function handle(): int
    {
        $lgaCode = (string) ($this->option('lga-code') ?? '');
        $client = (string) ($this->option('client') ?? '');

        if ($lgaCode === '' || $client === '') {
            $this->components->error('Both --lga-code and --client are required.');

            return self::FAILURE;
        }

        $lga = AdminBoundary::query()
            ->where('level', AdminBoundary::LEVEL_LGA)
            ->where('code', $lgaCode)
            ->first();

        if (! $lga instanceof AdminBoundary) {
            $this->components->error("No LGA loaded with code {$lgaCode}. Run geoverify:boundaries-load first.");

            return self::FAILURE;
        }

        // Nullable by construction: a state has no parent, and an LGA that failed
        // hierarchy resolution has none either.
        $state = AdminBoundary::query()->find($lga->parent_id);

        $attributes = [
            'name' => (string) ($this->option('name') ?? $lga->name),
            'contract_ref' => $this->option('contract-ref'),
            'state_code' => $state?->code,
            'admin_boundary_id' => $lga->id,
            'status' => 'active',
            'accuracy_threshold_m' => (int) $this->option('accuracy-threshold'),
            'default_h3_resolution' => (int) $this->option('resolution'),
        ];

        $area = CoverageArea::query()
            ->where('lga_code', $lgaCode)
            ->where('client_name', $client)
            ->first();

        if ($area instanceof CoverageArea) {
            $area->fill($attributes)->save();
        } else {
            // The boundary is NOT NULL, so it has to be written in the same
            // statement as the row. It is copied from admin_boundaries rather than
            // referenced: a mandate's ground is fixed at contract time, and a later
            // boundary release must not silently move what a client contracted for.
            $id = DB::scalar(
                'INSERT INTO coverage_areas (
                    client_name, contract_ref, name, state_code, lga_code, admin_boundary_id,
                    status, accuracy_threshold_m, default_h3_resolution, boundary, created_at, updated_at
                 )
                 SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ab.boundary, now(), now()
                   FROM admin_boundaries ab WHERE ab.id = ?
                 RETURNING id',
                [
                    $client,
                    $attributes['contract_ref'],
                    $attributes['name'],
                    $attributes['state_code'],
                    $lgaCode,
                    $lga->id,
                    $attributes['status'],
                    $attributes['accuracy_threshold_m'],
                    $attributes['default_h3_resolution'],
                    $lga->id,
                ],
            );

            $area = CoverageArea::query()->findOrFail($id);
        }

        // Refresh the mandate geometry on re-run so a corrected boundary load is
        // picked up deliberately, by re-running this command.
        DB::statement(
            'UPDATE coverage_areas SET boundary = (SELECT boundary FROM admin_boundaries WHERE id = ?) WHERE id = ?',
            [$lga->id, $area->id],
        );

        $areaKm2 = DB::scalar(
            'select round((ST_Area(boundary::geography)/1e6)::numeric, 1) from coverage_areas where id = ?',
            [$area->id],
        );

        $this->components->info("Coverage area #{$area->id} ready");
        $this->components->twoColumnDetail('Client', $client);
        $this->components->twoColumnDetail('Mandate', (string) $area->name);
        $this->components->twoColumnDetail('LGA', "{$lga->name} ({$lgaCode})");
        $this->components->twoColumnDetail(
            'State',
            $state instanceof AdminBoundary ? $state->name : 'unresolved',
        );
        $this->components->twoColumnDetail('Area', "{$areaKm2} km2");

        return self::SUCCESS;
    }
}
