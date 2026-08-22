<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Coverage\Actions\IngestFootprints;
use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Coverage\Models\ExternalFootprint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Loads the coverage denominator.
 *
 * Footprints are a work list, not a record. They miss buildings, merge adjacent
 * ones and occasionally invent them, which is why an officer can add a structure
 * with no footprint and dismiss a footprint that is not a building.
 */
final class FootprintsIngestCommand extends Command
{
    protected $signature = 'geoverify:footprints-ingest
        {coverage_area : Coverage area id}
        {--source=microsoft_global_ml : google_open_buildings or microsoft_global_ml}
        {--path=* : One or more newline-delimited GeoJSON files, .gz accepted}';

    protected $description = 'Ingest detected building footprints for a coverage area';

    public function handle(IngestFootprints $ingester): int
    {
        $area = CoverageArea::query()->find($this->argument('coverage_area'));

        if (! $area instanceof CoverageArea) {
            $this->components->error('No coverage area with that id.');

            return self::FAILURE;
        }

        /** @var list<string> $paths */
        $paths = array_values(array_filter((array) $this->option('path')));

        if ($paths === []) {
            $this->components->error('At least one --path is required.');

            return self::FAILURE;
        }

        $source = (string) $this->option('source');

        if (! in_array($source, [ExternalFootprint::SOURCE_GOOGLE, ExternalFootprint::SOURCE_MICROSOFT], true)) {
            $this->components->error("Unknown source '{$source}'.");

            return self::FAILURE;
        }

        $this->components->info("Ingesting {$source} footprints for {$area->name}");
        $started = microtime(true);

        try {
            $result = $ingester->ingest(
                $area,
                $paths,
                $source,
                function (int $read, int $near): void {
                    $this->output->write(sprintf(
                        "\r  read %s, inside bounding box %s   ",
                        number_format($read),
                        number_format($near),
                    ));
                },
            );
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine(2);
        $seconds = round(microtime(true) - $started, 1);

        $this->components->twoColumnDetail('Features read', number_format($result['read']));
        $this->components->twoColumnDetail('Inside bounding box', number_format($result['near']));
        $this->components->twoColumnDetail('Ingested (inside mandate)', number_format($result['ingested']));

        if ($result['skipped_invalid'] > 0) {
            $this->components->twoColumnDetail('<fg=yellow>Unparseable</>', (string) $result['skipped_invalid']);
        }

        if ($result['repaired'] > 0) {
            // Self-intersecting detections. Repaired to their largest part, and
            // reported because it says something about the source's quality.
            $this->components->twoColumnDetail(
                '<fg=yellow>Invalid geometry repaired</>',
                number_format($result['repaired']),
            );
        }

        $this->components->twoColumnDetail('Took', "{$seconds}s");
        $this->newLine();
        $this->reportDenominator($area);

        return self::SUCCESS;
    }

    /**
     * The numbers a supervisor actually needs: how the work is distributed, and
     * whether the source gave us anything to judge its own quality by.
     */
    private function reportDenominator(CoverageArea $area): void
    {
        /** @var object{cells: int, with: int, total: int, median: float|null, max: int, scored: int}|null $stats */
        $stats = DB::selectOne(<<<'SQL'
            SELECT count(*)                                                        AS cells,
                   count(*) FILTER (WHERE footprint_count > 0)                     AS "with",
                   COALESCE(sum(footprint_count), 0)                               AS total,
                   percentile_cont(0.5) WITHIN GROUP (ORDER BY footprint_count)
                       FILTER (WHERE footprint_count > 0)                          AS median,
                   COALESCE(max(footprint_count), 0)                               AS max
              FROM grid_cells WHERE coverage_area_id = ?
        SQL, [$area->id]);

        $scored = (int) DB::scalar(
            'select count(*) from external_footprints f
              join grid_cells g on g.id = f.grid_cell_id
             where g.coverage_area_id = ? and f.confidence is not null',
            [$area->id],
        );

        if ($stats === null) {
            return;
        }

        $this->components->twoColumnDetail('<fg=gray>Denominator</>', '');
        $this->components->twoColumnDetail('Cells in mandate', number_format($stats->cells));
        $this->components->twoColumnDetail('Cells holding footprints', number_format($stats->with));
        $this->components->twoColumnDetail('Footprints total', number_format($stats->total));
        $this->components->twoColumnDetail('Median per occupied cell', (string) (int) ($stats->median ?? 0));
        $this->components->twoColumnDetail('Busiest cell', number_format($stats->max));

        if ($scored === 0 && $stats->total > 0) {
            // Google publishes a per-building confidence; Microsoft does not for
            // Nigeria. Without it, low quality detections cannot be down-weighted,
            // and that limitation belongs on screen rather than in a footnote.
            $this->components->twoColumnDetail(
                '<fg=yellow>Confidence</>',
                'not published by this source for this region',
            );
        }
    }
}
