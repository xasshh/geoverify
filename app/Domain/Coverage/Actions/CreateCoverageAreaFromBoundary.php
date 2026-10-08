<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Actions;

use App\Domain\Coverage\Models\AdminBoundary;
use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A mandate drawn from a file the client sent: a forest reserve, a project
 * site, a cluster of villages. The ground an LGA does not describe.
 *
 * GeoJSON is read directly. KML, a zipped Shapefile and a GeoPackage are
 * converted with ogr2ogr, which also reprojects anything not already in
 * EPSG:4326. Every polygon in the file is unioned into one boundary and made
 * valid in PostGIS; points and lines are ignored, since a mandate is an area.
 *
 * The state is resolved spatially, as for any capture: whichever state the
 * boundary mostly falls in. No LGA code is stored, because the ground is not
 * an LGA, and it is exactly the field CreateCoverageArea upserts on.
 *
 * The file itself is kept on the private disk, so the ground a client
 * contracted can always be traced to what they sent.
 */
final class CreateCoverageAreaFromBoundary
{
    public function __construct(private readonly ReadVectorFile $files) {}

    /** Larger than any LGA in the country; a guard against uploading a state. */
    public const MAX_AREA_KM2 = 15_000;

    public function __invoke(
        UploadedFile $file,
        string $name,
        string $client,
        ?string $contractRef = null,
        int $resolution = 8,
        int $accuracyThresholdM = 15,
        ?User $actor = null,
    ): CoverageArea {
        $geojson = $this->readAsGeoJson($file);

        $shape = DB::selectOne(<<<'SQL'
            WITH input AS (
                SELECT ST_SetSRID(ST_GeomFromGeoJSON(f->>'geometry'), 4326) AS g
                  FROM jsonb_array_elements(?::jsonb -> 'features') AS f
                 WHERE f->'geometry' IS NOT NULL AND f->>'geometry' <> 'null'
            ),
            polygons AS (
                SELECT ST_CollectionExtract(ST_MakeValid(ST_Force2D(g)), 3) AS g FROM input
            ),
            merged AS (
                SELECT ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Union(g)), 3)) AS g
                  FROM polygons WHERE NOT ST_IsEmpty(g)
            )
            SELECT ST_AsEWKT(g) AS ewkt,
                   ST_Area(g::geography) / 1e6 AS km2,
                   ST_XMin(g) AS west, ST_YMin(g) AS south, ST_XMax(g) AS east, ST_YMax(g) AS north
              FROM merged
             WHERE g IS NOT NULL AND NOT ST_IsEmpty(g)
        SQL, [$geojson]);

        if ($shape === null) {
            throw ValidationException::withMessages(['boundary' => 'The file holds no area. A mandate needs at least one polygon.']);
        }

        // Coordinates that are not degrees mean a projection was lost on the
        // way: a Shapefile without its .prj, or a GeoJSON in metres.
        if ($shape->west < -180 || $shape->east > 180 || $shape->south < -90 || $shape->north > 90) {
            throw ValidationException::withMessages(['boundary' => 'The coordinates are not in degrees. Include the .prj file, or export in WGS84.']);
        }

        if ((float) $shape->km2 > self::MAX_AREA_KM2) {
            throw ValidationException::withMessages(['boundary' => sprintf(
                'The boundary covers %s km2, more than one mandate should (%s km2). Split it.',
                number_format((float) $shape->km2),
                number_format(self::MAX_AREA_KM2),
            )]);
        }

        if ((float) $shape->km2 < 0.01) {
            throw ValidationException::withMessages(['boundary' => 'The boundary is smaller than a hectare. Check the file is the right one.']);
        }

        return DB::transaction(function () use ($file, $shape, $name, $client, $contractRef, $resolution, $accuracyThresholdM, $actor): CoverageArea {
            // The state it mostly sits in, by area of overlap.
            $state = DB::selectOne(<<<'SQL'
                SELECT ab.code
                  FROM admin_boundaries ab
                 WHERE ab.level = ?
                   AND ST_Intersects(ab.boundary, ST_GeomFromEWKT(?))
                 ORDER BY ST_Area(ST_Intersection(ab.boundary, ST_GeomFromEWKT(?))) DESC
                 LIMIT 1
            SQL, [AdminBoundary::LEVEL_STATE, $shape->ewkt, $shape->ewkt]);

            $stored = 'boundaries/'.Str::lower((string) Str::ulid()).'-'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.strtolower($file->getClientOriginalExtension());
            Storage::disk('local')->putFileAs(dirname($stored), $file, basename($stored));

            $id = DB::scalar(<<<'SQL'
                INSERT INTO coverage_areas (
                    client_name, contract_ref, name, state_code, lga_code, admin_boundary_id,
                    status, accuracy_threshold_m, default_h3_resolution, boundary,
                    boundary_source, boundary_file, created_at, updated_at
                ) VALUES (?, ?, ?, ?, NULL, NULL, 'active', ?, ?, ST_GeomFromEWKT(?), 'uploaded', ?, now(), now())
                RETURNING id
            SQL, [
                $client,
                $contractRef,
                trim($name),
                $state?->code,
                $accuracyThresholdM,
                $resolution,
                $shape->ewkt,
                $stored,
            ]);

            $area = CoverageArea::query()->findOrFail($id);

            VerificationEvent::record($area, 'mandate.created', $actor, [
                'client' => $client,
                'boundary_source' => 'uploaded',
                'file' => $file->getClientOriginalName(),
                'area_km2' => round((float) $shape->km2, 2),
                'state_code' => $state?->code,
                'resolution' => $resolution,
            ]);

            return $area;
        });
    }

    /** The upload as one GeoJSON FeatureCollection in EPSG:4326. */
    private function readAsGeoJson(UploadedFile $file): string
    {
        $collection = ($this->files)($file->getRealPath(), $file->getClientOriginalExtension(), 'boundary');

        return json_encode($collection, JSON_THROW_ON_ERROR);
    }
}
