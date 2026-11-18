<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Cuts the street basemap out of Protomaps' daily OpenStreetMap build.
 *
 * The planet file is about 140 GB; `pmtiles extract` reads only the ranges
 * inside the box, so a Nigeria cut downloads what it keeps and nothing more.
 * The new file is written beside the old one and moved over it once complete,
 * so a map open while this runs keeps reading a whole archive.
 *
 * Run it again whenever a fresher map is wanted. Nothing else depends on the
 * date of the build.
 */
final class BasemapFetchCommand extends Command
{
    protected $signature = 'geoverify:basemap-fetch
        {--date= : A Protomaps build, as YYYYMMDD. Defaults to the newest}
        {--bbox= : west,south,east,north. Defaults to Nigeria}
        {--maxzoom= : Deepest zoom kept. Defaults to 14}';

    protected $description = 'Download the OpenStreetMap street basemap for Nigeria from Protomaps';

    public function handle(): int
    {
        $date = $this->option('date');
        $date = is_string($date) && $date !== '' ? $date : $this->newestBuild();

        if ($date === null || preg_match('/^\d{8}$/', $date) !== 1) {
            $this->components->error('No Protomaps build could be found. Pass --date=YYYYMMDD.');

            return self::FAILURE;
        }

        $bbox = (string) ($this->option('bbox') ?: config('geoverify.basemap.bbox'));

        if (preg_match('/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/', $bbox) !== 1) {
            $this->components->error('The box must be west,south,east,north in degrees.');

            return self::FAILURE;
        }

        $maxZoom = (int) ($this->option('maxzoom') ?: config('geoverify.basemap.max_zoom'));
        $source = str_replace('{date}', $date, (string) config('geoverify.basemap.planet_url'));
        $target = (string) config('geoverify.basemap.path');
        $partial = $target.'.partial';

        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0775, true) && ! is_dir(dirname($target))) {
            $this->components->error('Could not create '.dirname($target).'.');

            return self::FAILURE;
        }

        $this->components->info("Extracting build {$date}, box {$bbox}, zoom 0 to {$maxZoom}.");

        $result = Process::timeout(3 * 3600)
            ->run([
                (string) config('geoverify.imagery.pmtiles_binary'),
                'extract',
                $source,
                $partial,
                "--bbox={$bbox}",
                "--maxzoom={$maxZoom}",
                '--download-threads=8',
            ], function (string $type, string $output): void {
                $this->output->write($output);
            });

        if ($result->failed() || ! is_file($partial)) {
            @unlink($partial);
            $this->components->error('pmtiles extract failed: '.trim($result->errorOutput()));

            return self::FAILURE;
        }

        rename($partial, $target);

        $this->components->twoColumnDetail('Basemap', $target);
        $this->components->twoColumnDetail('Size', number_format((int) filesize($target) / 1_048_576, 1).' MB');

        return self::SUCCESS;
    }

    private function newestBuild(): ?string
    {
        try {
            $builds = Http::timeout(20)->get((string) config('geoverify.basemap.builds_url'))->json();
        } catch (Throwable) {
            return null;
        }

        $dates = [];

        foreach (is_array($builds) ? $builds : [] as $build) {
            $key = is_array($build) ? ($build['key'] ?? null) : null;

            if (is_string($key) && preg_match('/^(\d{8})\.pmtiles$/', $key, $m) === 1) {
                $dates[] = $m[1];
            }
        }

        rsort($dates);

        return $dates[0] ?? null;
    }
}
