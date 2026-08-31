<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Coverage\Actions\CreateCoverageArea;
use App\Domain\Coverage\Models\AdminBoundary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

        try {
            $area = app(CreateCoverageArea::class)(
                lgaCode: $lgaCode,
                client: $client,
                name: $this->option('name') === null ? null : (string) $this->option('name'),
                contractRef: $this->option('contract-ref') === null ? null : (string) $this->option('contract-ref'),
                accuracyThresholdM: (int) $this->option('accuracy-threshold'),
                resolution: (int) $this->option('resolution'),
            );
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $lga = AdminBoundary::query()->findOrFail($area->admin_boundary_id);
        $state = AdminBoundary::query()->find($lga->parent_id);

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
