<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Actions;

use App\Domain\AreaCapture\Jobs\RefreshCellAreaProgress;
use App\Domain\AreaCapture\Jobs\ScoreAreaRevision;
use App\Domain\AreaCapture\Models\AreaFeature;
use App\Domain\AreaCapture\Models\AreaFeatureRevision;
use App\Domain\Campaign\Actions\ValidateFeatureAttributes;
use App\Domain\Campaign\Enums\CaptureMode;
use App\Domain\Campaign\Enums\GeometryType;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\FeatureClass;
use App\Domain\Campaign\Models\FeatureClassVersion;
use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Field\Models\Assignment;
use App\Domain\Field\Models\FieldSession;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Records one drawing of a feature of the land, from the field or the desk.
 *
 * Everything that decides whether a shape is acceptable runs in PostGIS
 * (validity, repair, area, length, containment, overlap), and the area and
 * length are always computed here, never taken from the client. A field
 * capture must also sit inside the officer's own open cells.
 *
 * A new feature uuid creates the feature; a known one adds a revision to it,
 * which is how an officer's walk of a desk-drawn farm keeps both shapes.
 */
final class CaptureAreaFeature
{
    /**
     * A polygon may overlap an exclusive neighbour by this much before it is
     * refused: two officers will never trace a shared field edge to the metre.
     */
    private const OVERLAP_SLACK_M2 = 50.0;

    private const OVERLAP_SLACK_FRACTION = 0.02;

    /** A repair that moves more of the area than this is flagged for review. */
    private const MATERIAL_REPAIR_PCT = 5.0;

    public function __construct(private readonly ValidateFeatureAttributes $answers) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function __invoke(array $input, User $actor): AreaFeatureRevision
    {
        if (! is_string($input['client_uuid'] ?? null) || ! Str::isUuid($input['client_uuid'])) {
            $this->fail('client_uuid', 'A capture needs its own uuid.');
        }

        $method = (string) ($input['capture_method'] ?? '');

        if (! in_array($method, [
            AreaFeatureRevision::METHOD_DESK, AreaFeatureRevision::METHOD_WALKED, AreaFeatureRevision::METHOD_DRAWN,
            AreaFeatureRevision::METHOD_IMPORTED, AreaFeatureRevision::METHOD_VERIFIED,
        ], true)) {
            $this->fail('capture_method', 'Unknown capture method.');
        }

        $inTheField = in_array($method, AreaFeatureRevision::FIELD_METHODS, true);

        $class = FeatureClass::query()->find((int) ($input['feature_class_id'] ?? 0))
            ?? $this->fail('feature_class_id', 'That feature class does not exist.');

        $version = FeatureClassVersion::query()
            ->where('feature_class_id', $class->id)
            ->where('version', (int) ($input['class_version'] ?? 0))
            ->first() ?? $this->fail('class_version', 'That version of the class form does not exist.');

        [$area, $assignment] = $this->ground($input, $actor, $inTheField);

        $campaign = Campaign::query()->find($area->campaign_id)
            ?? $this->fail('coverage_area_id', 'This ground is not under a campaign.');

        if (! $campaign->captures(CaptureMode::AreaFeatures)) {
            $this->fail('feature_class_id', 'This campaign does not capture area features.');
        }

        if ($class->campaign_id !== $campaign->id) {
            $this->fail('feature_class_id', 'That class belongs to a different campaign.');
        }

        $clean = ($this->answers)($version, is_array($input['answers'] ?? null) ? $input['answers'] : [], $inTheField);

        $geojson = $this->geojson($input['geometry'] ?? null);
        $shape = $this->shape($geojson, $class->geometry_type);

        $this->checkSize($shape, $campaign);
        $this->checkInsideCampaign($shape->ewkt, $campaign);

        if ($inTheField && $assignment !== null) {
            $this->checkInsideAssignments($shape->ewkt, $actor, $campaign);
        }

        $featureUuid = (string) ($input['feature_uuid'] ?? '');
        $existing = $featureUuid === '' ? null : AreaFeature::query()->where('client_uuid', $featureUuid)->first();

        if ($existing !== null && $existing->campaign_id !== $campaign->id) {
            $this->fail('feature_uuid', 'That feature belongs to a different campaign.');
        }

        if ($class->exclusivity_group !== null && $class->geometry_type === GeometryType::Polygon) {
            $this->checkOverlap($shape->ewkt, $class->exclusivity_group, $campaign, $existing?->id);
        }

        $session = null;

        if (is_string($input['field_session_client_uuid'] ?? null)) {
            $session = FieldSession::query()
                ->where('client_uuid', $input['field_session_client_uuid'])
                ->where('user_id', $actor->id)
                ->first();
        }

        return DB::transaction(function () use ($input, $actor, $method, $class, $version, $area, $assignment, $campaign, $clean, $shape, $existing, $featureUuid, $inTheField, $session): AreaFeatureRevision {
            $feature = $existing ?? AreaFeature::query()->create([
                'client_uuid' => $featureUuid !== '' ? $featureUuid : (string) Str::uuid(),
                'campaign_id' => $campaign->id,
                'coverage_area_id' => $area->id,
                'feature_class_id' => $class->id,
                'verification_status' => AreaFeature::VERIFICATION_UNVERIFIED,
                'status' => AreaFeature::STATUS_LIVE,
            ]);

            $revisionId = (int) DB::scalar(<<<'SQL'
                INSERT INTO area_feature_revisions (
                    client_uuid, area_feature_id, feature_class_version_id, answers, capture_method,
                    basemap_layer_id, imagery_date, gps_accuracy_m, field_session_id, assignment_id,
                    captured_by, captured_at, area_ha, length_m, geometry_repaired, repair_area_change_pct,
                    notes, geom, created_at, updated_at
                ) VALUES (?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ST_GeomFromEWKT(?), now(), now())
                RETURNING id
            SQL, [
                (string) $input['client_uuid'],
                $feature->id,
                $version->id,
                json_encode((object) $clean, JSON_THROW_ON_ERROR),
                $method,
                isset($input['basemap_layer_id']) ? (int) $input['basemap_layer_id'] : null,
                is_string($input['imagery_date'] ?? null) ? $input['imagery_date'] : null,
                is_numeric($input['gps_accuracy_m'] ?? null) ? (float) $input['gps_accuracy_m'] : null,
                $session?->id,
                $assignment?->id,
                $actor->id,
                is_string($input['captured_at'] ?? null) ? Carbon::parse($input['captured_at']) : now(),
                $shape->area_ha,
                $shape->length_m,
                $shape->repaired ? 'true' : 'false',
                $shape->change_pct,
                is_string($input['notes'] ?? null) ? mb_substr($input['notes'], 0, 2000) : null,
                $shape->ewkt,
            ]);

            // A field capture is ground truth by being there; a desk drawing
            // waits for an officer. A reclassification moves the feature.
            $feature->forceFill([
                'current_revision_id' => $revisionId,
                'feature_class_id' => $class->id,
                'verification_status' => $inTheField ? AreaFeature::VERIFICATION_VERIFIED : $feature->verification_status,
            ])->save();

            // The cells it touches, from the campaign's own grid. Derived, so
            // replaced wholesale on each revision.
            DB::delete('DELETE FROM area_feature_cells WHERE area_feature_id = ?', [$feature->id]);
            DB::insert(<<<'SQL'
                INSERT INTO area_feature_cells (area_feature_id, grid_cell_id)
                SELECT ?, g.id
                  FROM grid_cells g
                  JOIN coverage_areas ca ON ca.id = g.coverage_area_id
                 WHERE ca.campaign_id = ?
                   AND ST_Intersects(g.boundary, ST_GeomFromEWKT(?))
            SQL, [$feature->id, $campaign->id, $shape->ewkt]);

            VerificationEvent::record($feature, $existing === null ? 'area_feature.captured' : 'area_feature.revised', $actor, array_filter([
                'revision_id' => $revisionId,
                'class' => $class->key,
                'version' => $version->version,
                'method' => $method,
                'area_ha' => $shape->area_ha === null ? null : (float) $shape->area_ha,
                'length_m' => $shape->length_m === null ? null : (float) $shape->length_m,
                'geometry_repaired' => $shape->repaired ?: null,
                'repair_area_change_pct' => $shape->change_pct,
            ], static fn (mixed $v): bool => $v !== null));

            $cells = DB::table('area_feature_cells')->where('area_feature_id', $feature->id)->pluck('grid_cell_id');

            DB::afterCommit(static function () use ($revisionId, $cells): void {
                ScoreAreaRevision::dispatch($revisionId);

                foreach ($cells as $cellId) {
                    RefreshCellAreaProgress::dispatch((int) $cellId);
                }
            });

            return AreaFeatureRevision::query()->findOrFail($revisionId);
        });
    }

    /**
     * Where the capture is. In the field, the officer's open assignment, which
     * also names the mandate; from the desk, the mandate given.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: CoverageArea, 1: Assignment|null}
     */
    private function ground(array $input, User $actor, bool $inTheField): array
    {
        if ($inTheField) {
            $assignment = Assignment::query()
                ->open()
                ->whereKey((int) ($input['assignment_id'] ?? 0))
                ->where('user_id', $actor->id)
                ->first() ?? $this->fail('assignment_id', 'This capture is not inside one of your open assignments.');

            $area = CoverageArea::query()
                ->whereIn('id', DB::table('grid_cells')->where('id', $assignment->grid_cell_id)->select('coverage_area_id'))
                ->firstOrFail();

            return [$area, $assignment];
        }

        $area = CoverageArea::query()->find((int) ($input['coverage_area_id'] ?? 0))
            ?? $this->fail('coverage_area_id', 'That mandate does not exist.');

        return [$area, null];
    }

    /** @return array<string, mixed> */
    private function geojson(mixed $geometry): array
    {
        if (is_string($geometry)) {
            try {
                $geometry = json_decode($geometry, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->fail('geometry', 'The shape could not be read.');
            }
        }

        if (! is_array($geometry) || ! is_string($geometry['type'] ?? null) || ! is_array($geometry['coordinates'] ?? null)) {
            $this->fail('geometry', 'The shape could not be read.');
        }

        /** @var array<string, mixed> $geometry */
        return $geometry;
    }

    /**
     * Validity, repair, area and length, in one round trip.
     *
     * @param  array<string, mixed>  $geojson
     */
    private function shape(array $geojson, GeometryType $expected): object
    {
        $wanted = match ($expected) {
            GeometryType::Point => ['ST_Point'],
            GeometryType::Line => ['ST_LineString'],
            GeometryType::Polygon => ['ST_Polygon', 'ST_MultiPolygon'],
        };

        try {
            $row = DB::selectOne(<<<'SQL'
                WITH raw AS (
                    SELECT ST_Force2D(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) AS g
                )
                SELECT ST_GeometryType(g) AS kind,
                       ST_IsValid(g) AS valid,
                       ST_IsSimple(g) AS simple,
                       ST_NPoints(g) AS points,
                       ST_IsEmpty(g) AS empty,
                       ST_XMin(g) AS west, ST_XMax(g) AS east, ST_YMin(g) AS south, ST_YMax(g) AS north
                  FROM raw
            SQL, [json_encode($geojson, JSON_THROW_ON_ERROR)]);
        } catch (QueryException) {
            $this->fail('geometry', 'The shape could not be read.');
        }

        if ($row === null || $row->empty || ! in_array($row->kind, $wanted, true)) {
            $this->fail('geometry', 'This class is drawn as '.strtolower($expected->label()).', and that shape is not one.');
        }

        if ($row->west < -180 || $row->east > 180 || $row->south < -90 || $row->north > 90) {
            $this->fail('geometry', 'The coordinates are not in degrees.');
        }

        if ($expected === GeometryType::Line && ((int) $row->points < 2 || ! $row->simple)) {
            $this->fail('geometry', 'A line may not cross itself. Redraw it so it does not loop back over its own path.');
        }

        if ($expected === GeometryType::Polygon && (int) $row->points < 4) {
            $this->fail('geometry', 'An area needs at least three corners.');
        }

        // Points and simple lines are taken as drawn. A polygon that is not
        // valid is repaired once; one that cannot be, or that the repair
        // changes materially, is refused or flagged rather than quietly reshaped.
        $repaired = false;
        $changePct = null;
        $sql = 'ST_Force2D(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))';

        if ($expected === GeometryType::Polygon && ! $row->valid) {
            $repair = DB::selectOne(<<<SQL
                WITH raw AS (SELECT {$sql} AS g),
                     fixed AS (SELECT g, ST_Multi(ST_CollectionExtract(ST_MakeValid(g), 3)) AS f FROM raw)
                SELECT ST_IsEmpty(f) AS empty,
                       abs(ST_Area(f::geography) - ST_Area(ST_Buffer(g, 0)::geography))
                         / nullif(ST_Area(ST_Buffer(g, 0)::geography), 0) * 100 AS change_pct
                  FROM fixed
            SQL, [json_encode($geojson, JSON_THROW_ON_ERROR)]);

            if ($repair === null || $repair->empty) {
                $this->fail('geometry', 'The area crosses itself and cannot be repaired. Redraw it without the edges crossing.');
            }

            $repaired = true;
            $changePct = $repair->change_pct === null ? null : round((float) $repair->change_pct, 2);
            $sql = "ST_Multi(ST_CollectionExtract(ST_MakeValid({$sql}), 3))";
        }

        $measured = DB::selectOne(<<<SQL
            WITH g AS (SELECT {$sql} AS g)
            SELECT ST_AsEWKT(g) AS ewkt,
                   CASE WHEN GeometryType(g) IN ('POLYGON', 'MULTIPOLYGON') THEN round((ST_Area(g::geography) / 10000)::numeric, 4) END AS area_ha,
                   CASE WHEN GeometryType(g) = 'LINESTRING' THEN round(ST_Length(g::geography)::numeric, 2) END AS length_m
              FROM g
        SQL, [json_encode($geojson, JSON_THROW_ON_ERROR)]);

        if ($measured === null) {
            $this->fail('geometry', 'The shape could not be read.');
        }

        $measured->repaired = $repaired;
        $measured->change_pct = $changePct;
        $measured->material_repair = $changePct !== null && $changePct > self::MATERIAL_REPAIR_PCT;

        return $measured;
    }

    private function checkSize(object $shape, Campaign $campaign): void
    {
        if ($shape->area_ha !== null && $campaign->min_mapping_unit_ha !== null
            && (float) $shape->area_ha < (float) $campaign->min_mapping_unit_ha) {
            $this->fail('geometry', sprintf(
                'This area is %s ha, smaller than the %s ha this campaign records.',
                rtrim(rtrim(number_format((float) $shape->area_ha, 4), '0'), '.'),
                rtrim(rtrim((string) $campaign->min_mapping_unit_ha, '0'), '.'),
            ));
        }

        if ($shape->material_repair) {
            $this->fail('geometry', sprintf(
                'Repairing this area would change it by %s%%. Redraw it so its edges do not cross.',
                number_format((float) $shape->change_pct, 1),
            ));
        }
    }

    /** Inside the union of the campaign's mandates, give or take its tolerance. */
    private function checkInsideCampaign(string $ewkt, Campaign $campaign): void
    {
        $inside = (bool) DB::scalar(<<<'SQL'
            SELECT ST_Covers(
                       ST_Buffer(ST_Union(ca.boundary)::geography, ?)::geometry,
                       ST_GeomFromEWKT(?)
                   )
              FROM coverage_areas ca
             WHERE ca.campaign_id = ?
        SQL, [(float) ($campaign->boundary_tolerance_m ?? 25), $ewkt, $campaign->id]);

        if (! $inside) {
            $this->fail('geometry', 'This shape runs outside the campaign\'s ground.');
        }
    }

    /** Inside the cells this officer holds open, give or take the tolerance. */
    private function checkInsideAssignments(string $ewkt, User $actor, Campaign $campaign): void
    {
        $inside = (bool) DB::scalar(<<<'SQL'
            SELECT COALESCE(ST_Covers(
                       ST_Buffer(ST_Union(g.boundary)::geography, ?)::geometry,
                       ST_GeomFromEWKT(?)
                   ), false)
              FROM assignments a
              JOIN grid_cells g ON g.id = a.grid_cell_id
             WHERE a.user_id = ? AND a.closed_at IS NULL
        SQL, [(float) ($campaign->boundary_tolerance_m ?? 25), $ewkt, $actor->id]);

        if (! $inside) {
            $this->fail('geometry', 'This shape runs outside your assigned cells. Ask your supervisor for the cells next door.');
        }
    }

    /** A piece of ground is one land cover, not two: refuse a real overlap. */
    private function checkOverlap(string $ewkt, string $group, Campaign $campaign, ?int $exceptFeatureId): void
    {
        $conflicts = DB::select(<<<'SQL'
            WITH mine AS (SELECT ST_GeomFromEWKT(?) AS g)
            SELECT f.client_uuid, fc.label,
                   ST_Area(ST_Intersection(r.geom, mine.g)::geography) AS overlap_m2,
                   LEAST(ST_Area(r.geom::geography), ST_Area(mine.g::geography)) AS smaller_m2
              FROM area_features f
              JOIN area_feature_revisions r ON r.id = f.current_revision_id
              JOIN feature_classes fc ON fc.id = f.feature_class_id
              CROSS JOIN mine
             WHERE f.campaign_id = ?
               AND f.status = 'live'
               AND fc.exclusivity_group = ?
               AND (?::bigint IS NULL OR f.id <> ?::bigint)
               AND GeometryType(r.geom) IN ('POLYGON', 'MULTIPOLYGON')
               AND ST_Intersects(r.geom, mine.g)
        SQL, [$ewkt, $campaign->id, $group, $exceptFeatureId, $exceptFeatureId]);

        $real = array_values(array_filter(
            $conflicts,
            static fn (object $c): bool => (float) $c->overlap_m2 > max(self::OVERLAP_SLACK_M2, (float) $c->smaller_m2 * self::OVERLAP_SLACK_FRACTION),
        ));

        if ($real !== []) {
            throw ValidationException::withMessages([
                'geometry' => sprintf(
                    'This overlaps %s already recorded here (%s). Adjust the edge, or reclassify one of them.',
                    implode(', ', array_map(static fn (object $c): string => strtolower((string) $c->label), $real)),
                    implode(', ', array_map(static fn (object $c): string => (string) $c->client_uuid, $real)),
                ),
                'conflicts' => array_map(static fn (object $c): string => (string) $c->client_uuid, $real),
            ]);
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
