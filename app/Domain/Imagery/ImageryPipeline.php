<?php

declare(strict_types=1);

namespace App\Domain\Imagery;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Turns satellite scenes into one raster PMTiles archive clipped to a mandate.
 *
 *  1. gdalwarp reads only the pixels inside the boundary, by byte range,
 *     straight from the cloud-optimised GeoTIFFs; reprojects to web mercator
 *     (tiles in different UTM zones meet here); and clips to the boundary with
 *     transparency outside it.
 *  2. gdal_translate cuts web tiles into MBTiles as WEBP, which keeps the
 *     transparency at a fraction of PNG's size.
 *  3. gdaladdo builds the lower zooms.
 *  4. pmtiles converts the result into the single file the phone reads by range.
 *
 * Bound in the container so a test can stand in for it: the commands need GDAL
 * and network access, which the test suite has neither of.
 */
class ImageryPipeline
{
    /**
     * @param  list<array{href: string}>  $scenes
     * @param  string  $boundaryGeoJson  a FeatureCollection in EPSG:4326
     * @param  callable(string, int): void  $stage  told what is happening, with a percentage
     * @return string the path of the built archive inside $workDir
     */
    public function build(array $scenes, string $boundaryGeoJson, string $workDir, callable $stage): string
    {
        if ($scenes === []) {
            throw new RuntimeException('There are no scenes to build from.');
        }

        file_put_contents("{$workDir}/boundary.geojson", $boundaryGeoJson);

        $env = [
            // Read cloud GeoTIFFs by range without listing their directories,
            // which on S3 is slow and pointless.
            'GDAL_DISABLE_READDIR_ON_OPEN' => 'EMPTY_DIR',
            'CPL_VSIL_CURL_ALLOWED_EXTENSIONS' => '.tif,.tiff',
            'GDAL_HTTP_MULTIRANGE' => 'YES',
            'GDAL_HTTP_MERGE_CONSECUTIVE_RANGES' => 'YES',
            'GDAL_HTTP_MAX_RETRY' => '5',
            'GDAL_HTTP_RETRY_DELAY' => '3',
            'VSI_CACHE' => 'TRUE',
        ];

        $stage('Reading the satellite images over the mandate', 25);
        $this->run([
            $this->gdal('gdalwarp'),
            '-t_srs', 'EPSG:3857',
            '-cutline', "{$workDir}/boundary.geojson",
            '-crop_to_cutline',
            '-srcnodata', '0',
            '-dstalpha',
            '-r', 'bilinear',
            '-multi', '-wo', 'NUM_THREADS=ALL_CPUS',
            '-co', 'TILED=YES', '-co', 'COMPRESS=DEFLATE', '-co', 'BIGTIFF=IF_SAFER',
            '-overwrite',
            ...array_map(static fn (array $s): string => '/vsicurl/'.$s['href'], $scenes),
            "{$workDir}/warped.tif",
        ], $env);

        $stage('Cutting map tiles', 65);
        $this->run([
            $this->gdal('gdal_translate'),
            '-of', 'MBTiles',
            '-co', 'TILE_FORMAT=WEBP',
            '-co', 'QUALITY=80',
            '-co', 'RESAMPLING=BILINEAR',
            "{$workDir}/warped.tif",
            "{$workDir}/imagery.mbtiles",
        ], $env);

        $stage('Building the zoomed-out views', 80);
        $this->run([
            $this->gdal('gdaladdo'),
            '-r', 'average',
            "{$workDir}/imagery.mbtiles",
            '2', '4', '8', '16', '32', '64', '128',
        ], $env);

        $stage('Packing for the phone', 90);
        $this->run([
            (string) config('geoverify.imagery.pmtiles_binary'),
            'convert',
            "{$workDir}/imagery.mbtiles",
            "{$workDir}/imagery.pmtiles",
        ], $env);

        if (! is_file("{$workDir}/imagery.pmtiles")) {
            throw new RuntimeException('The imagery archive was not produced.');
        }

        return "{$workDir}/imagery.pmtiles";
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function run(array $command, array $env): void
    {
        $result = Process::timeout((int) config('geoverify.imagery.timeout_seconds'))
            ->env($env)
            ->run($command);

        if ($result->failed()) {
            // The last lines of GDAL's complaint are the useful ones.
            $why = trim(implode("\n", array_slice(explode("\n", trim($result->errorOutput())), -3)));

            throw new RuntimeException(basename($command[0]).' failed: '.($why !== '' ? $why : 'no detail given'));
        }
    }

    private function gdal(string $tool): string
    {
        $bin = rtrim((string) config('geoverify.imagery.gdal_bin'), '/');

        return $bin === '' ? $tool : "{$bin}/{$tool}";
    }
}
