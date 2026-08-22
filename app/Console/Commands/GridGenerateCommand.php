<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Coverage\Actions\GenerateGrid;
use App\Domain\Coverage\Models\CoverageArea;
use Illuminate\Console\Command;
use Throwable;

/** Cuts a mandate into the H3 cells officers are assigned. */
final class GridGenerateCommand extends Command
{
    protected $signature = 'geoverify:grid-generate
        {coverage_area : Coverage area id}
        {--resolution= : H3 resolution, defaults to the mandate setting}
        {--containment=overlapping : center, overlapping or full}';

    protected $description = 'Generate the H3 work grid for a coverage area';

    public function handle(GenerateGrid $generator): int
    {
        $area = CoverageArea::query()->find($this->argument('coverage_area'));

        if (! $area instanceof CoverageArea) {
            $this->components->error('No coverage area with that id.');

            return self::FAILURE;
        }

        $resolution = $this->option('resolution') !== null
            ? (int) $this->option('resolution')
            : $area->default_h3_resolution;

        $containment = (string) $this->option('containment');

        $this->components->info("Tiling {$area->name} at resolution {$resolution} ({$containment})");

        $started = microtime(true);

        try {
            $result = $generator->generate($area, $resolution, $containment);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $seconds = round(microtime(true) - $started, 1);

        $this->components->twoColumnDetail('Cells created', (string) $result['created']);

        if ($result['existing'] > 0) {
            $this->components->twoColumnDetail('Already present', (string) $result['existing']);
        }

        if ($result['claimed_elsewhere'] > 0) {
            // A cell belongs to exactly one mandate. Overlapping mandates therefore
            // leave the second one with holes, which must never be silent.
            $this->components->twoColumnDetail(
                '<fg=yellow>Held by another mandate</>',
                "{$result['claimed_elsewhere']} cells are not in this grid",
            );
        }

        $this->components->twoColumnDetail('Cells in mandate', (string) $result['total']);
        $this->components->twoColumnDetail('Took', "{$seconds}s");

        return self::SUCCESS;
    }
}
