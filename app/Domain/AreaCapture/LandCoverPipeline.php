<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * ESA WorldCover over a mandate, as land cover polygons.
 *
 * WorldCover 10 m (2021, CC BY 4.0) is already classified: tree cover,
 * shrubland, grassland, cropland, built-up, bare, water, wetland. Read by
 * range straight from its cloud GeoTIFF tiles, clipped to the boundary, specks
 * smaller than the minimum mapping unit sieved away, the rest turned into
 * polygons and simplified to about 4 m. Proved on the production server over
 * Makurdi: 30 km2 became 280 polygons in under a second.
 *
 * Bound in the container so a test can stand in for it, like ImageryPipeline.
 */
class LandCoverPipeline
{
    private const TILE_URL = 'https://esa-worldcover.s3.eu-central-1.amazonaws.com/v200/2021/map/ESA_WorldCover_10m_2021_v200_%s_Map.tif';

    /**
     * @param  array{west: float, south: float, east: float, north: float}  $bounds
     * @return string the path of a GeoJSON FeatureCollection whose features carry a `class` property
     */
    public function build(string $boundaryGeoJson, array $bounds, int $minimumPixels, string $workDir): string
    {
        file_put_contents("{$workDir}/boundary.geojson", $boundaryGeoJson);

        $env = [
            'GDAL_DISABLE_READDIR_ON_OPEN' => 'EMPTY_DIR',
            'CPL_VSIL_CURL_ALLOWED_EXTENSIONS' => '.tif',
            'GDAL_HTTP_MULTIRANGE' => 'YES',
            'GDAL_HTTP_MAX_RETRY' => '5',
            'VSI_CACHE' => 'TRUE',
        ];

        $tiles = array_map(
            static fn (string $tile): string => '/vsicurl/'.sprintf(self::TILE_URL, $tile),
            self::tiles($bounds),
        );

        $this->run([$this->gdal('gdalwarp'), '-q', '-cutline', "{$workDir}/boundary.geojson", '-crop_to_cutline',
            '-dstnodata', '0', '-r', 'near', '-co', 'COMPRESS=DEFLATE', '-overwrite', ...$tiles, "{$workDir}/cover.tif"], $env);

        $this->run([$this->gdal('gdal_sieve.py'), '-q', '-st', (string) max(1, $minimumPixels), '-8',
            "{$workDir}/cover.tif", "{$workDir}/sieved.tif"], $env);

        $this->run([$this->gdal('gdal_polygonize.py'), '-q', '-8', "{$workDir}/sieved.tif",
            '-f', 'GeoJSON', "{$workDir}/raw.geojson", 'cover', 'class'], $env);

        $this->run([$this->gdal('ogr2ogr'), '-f', 'GeoJSON', '-simplify', '0.00004', '-where', 'class > 0',
            "{$workDir}/cover.geojson", "{$workDir}/raw.geojson"], $env);

        if (! is_file("{$workDir}/cover.geojson")) {
            throw new RuntimeException('The land cover was not produced.');
        }

        return "{$workDir}/cover.geojson";
    }

    /**
     * The 3 by 3 degree WorldCover tiles a box touches, named as ESA names
     * them: N06E006 is 6 to 9 north, 6 to 9 east.
     *
     * @param  array{west: float, south: float, east: float, north: float}  $bounds
     * @return list<string>
     */
    public static function tiles(array $bounds): array
    {
        $names = [];

        for ($lat = (int) floor($bounds['south'] / 3) * 3; $lat < $bounds['north']; $lat += 3) {
            for ($lon = (int) floor($bounds['west'] / 3) * 3; $lon < $bounds['east']; $lon += 3) {
                $names[] = sprintf('%s%02d%s%03d', $lat < 0 ? 'S' : 'N', abs($lat), $lon < 0 ? 'W' : 'E', abs($lon));
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function run(array $command, array $env): void
    {
        $result = Process::timeout((int) config('geoverify.imagery.timeout_seconds'))->env($env)->run($command);

        if ($result->failed()) {
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
