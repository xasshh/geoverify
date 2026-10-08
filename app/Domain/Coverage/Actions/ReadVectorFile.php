<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Actions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * A map file somebody sent, as one GeoJSON FeatureCollection in EPSG:4326.
 *
 * GeoJSON is read directly. KML, a zipped Shapefile and a GeoPackage go
 * through ogr2ogr, which also reprojects anything not already in degrees.
 * Shared by mandates drawn from a boundary file and by bulk area imports, so a
 * file a client sends is read the same way whatever it is for.
 */
final class ReadVectorFile
{
    public const EXTENSIONS = ['geojson', 'json', 'kml', 'zip', 'gpkg'];

    /**
     * @param  string  $path  the file on disk
     * @param  string  $extension  its original extension, lower case
     * @param  string  $field  the form field an error belongs to
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    public function __invoke(string $path, string $extension, string $field = 'file'): array
    {
        $extension = strtolower($extension);

        if (in_array($extension, ['geojson', 'json'], true)) {
            return $this->asCollection((string) file_get_contents($path), $field);
        }

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw ValidationException::withMessages([$field => 'Send GeoJSON, KML, a zipped Shapefile or a GeoPackage.']);
        }

        $work = storage_path('app/vector-work/'.Str::lower((string) Str::ulid()));
        File::ensureDirectoryExists($work);

        try {
            $input = "{$work}/input.{$extension}";
            copy($path, $input);

            // A zipped Shapefile is read in place through GDAL's zip reader.
            $source = $extension === 'zip' ? "/vsizip/{$input}" : $input;
            $bin = rtrim((string) config('geoverify.imagery.gdal_bin'), '/');

            $result = Process::timeout(300)->run([
                $bin === '' ? 'ogr2ogr' : "{$bin}/ogr2ogr",
                '-f', 'GeoJSON',
                '-t_srs', 'EPSG:4326',
                "{$work}/out.geojson",
                $source,
            ]);

            if ($result->failed() || ! is_file("{$work}/out.geojson")) {
                throw ValidationException::withMessages([
                    $field => 'The file could not be read as a map layer. '.Str::limit(trim($result->errorOutput()), 200),
                ]);
            }

            return $this->asCollection((string) file_get_contents("{$work}/out.geojson"), $field);
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * A bare geometry or a single Feature, wrapped as a collection.
     *
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function asCollection(string $json, string $field): array
    {
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([$field => 'The GeoJSON could not be read.']);
        }

        if (! is_array($data) || ! is_string($data['type'] ?? null)) {
            throw ValidationException::withMessages([$field => 'The GeoJSON could not be read.']);
        }

        /** @var list<array<string, mixed>> $features */
        $features = match ($data['type']) {
            'FeatureCollection' => array_values(array_filter((array) ($data['features'] ?? []), 'is_array')),
            'Feature' => [$data],
            default => [['type' => 'Feature', 'properties' => [], 'geometry' => $data]],
        };

        return ['type' => 'FeatureCollection', 'features' => $features];
    }
}
