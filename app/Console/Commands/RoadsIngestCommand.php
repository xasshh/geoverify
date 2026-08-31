<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Coverage\Actions\IngestRoads;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads the street network so a map has landmarks.
 *
 * Roads are reference data like boundaries: loaded from an extract, never
 * created in the app, and replaceable by a client's own authoritative network.
 * See docs/geodata.md for where the extract comes from and how to shape it.
 */
final class RoadsIngestCommand extends Command
{
    protected $signature = 'geoverify:roads-ingest
        {--source=openstreetmap : Label recorded against every way loaded}
        {--path=* : One or more newline-delimited GeoJSON files, .gz accepted}';

    protected $description = 'Ingest the street network from newline-delimited GeoJSON';

    public function handle(IngestRoads $ingester): int
    {
        /** @var list<string> $paths */
        $paths = array_values(array_filter((array) $this->option('path')));

        if ($paths === []) {
            $this->components->error('At least one --path is required.');

            return self::FAILURE;
        }

        foreach ($paths as $path) {
            if (! is_readable($path)) {
                $this->components->error("Cannot read {$path}.");

                return self::FAILURE;
            }
        }

        $source = (string) $this->option('source');
        $this->components->info("Ingesting {$source} roads");

        $result = $ingester->ingest($paths, $source, function (int $read, int $ingested): void {
            $this->output->write(sprintf("\r  read %s, written %s   ", number_format($read), number_format($ingested)));
        });

        $this->newLine(2);
        $this->components->twoColumnDetail('Features read', number_format($result['read']));
        $this->components->twoColumnDetail('Written', number_format($result['ingested']));
        $this->components->twoColumnDetail('Skipped, no class or not a line', number_format($result['skipped']));

        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>Network</>', '');

        /** @var list<object{highway: string, ways: int, km: float}> $byClass */
        $byClass = DB::select(
            'select highway, count(*) as ways, round((sum(ST_Length(geometry::geography)) / 1000)::numeric, 1) as km
               from roads group by highway order by sum(ST_Length(geometry::geography)) desc limit 12'
        );

        foreach ($byClass as $row) {
            $this->components->twoColumnDetail(
                $row->highway,
                number_format((int) $row->ways).' ways, '.$row->km.' km',
            );
        }

        return self::SUCCESS;
    }
}
