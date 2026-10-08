<?php

declare(strict_types=1);

namespace App\Http\Controllers\Field;

use App\Domain\AreaCapture\Models\AreaFeatureRevision;
use App\Domain\Campaign\Enums\CaptureMode;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\FeatureClass;
use App\Domain\Field\Models\Assignment;
use App\Domain\Media\Actions\StorePhotograph;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The officer's area capture screen, and the photographs taken on it.
 *
 * Opened online like the building capture screen (Inertia navigation needs the
 * server), then works offline: the catalogue and the features already
 * recorded travel in the page, the captures go through the sync queue.
 */
final class AreaCaptureController
{
    public function show(Request $request, Assignment $assignment): Response
    {
        Gate::authorize('start', $assignment);

        $cell = $assignment->gridCell;
        abort_if($cell === null, 404);

        $campaign = Campaign::query()->find($cell->coverageArea?->campaign_id);
        abort_if($campaign === null || ! $campaign->captures(CaptureMode::AreaFeatures), 404);

        /** @var object{lon: float, lat: float} $centre */
        $centre = DB::selectOne(
            'SELECT ST_X(centroid::geometry) AS lon, ST_Y(centroid::geometry) AS lat FROM grid_cells WHERE id = ?',
            [$cell->id],
        );

        $classes = FeatureClass::query()
            ->where('campaign_id', $campaign->id)
            ->where('is_active', true)
            ->with('latestVersion')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('field/AreaCapture', [
            'assignmentId' => $assignment->id,
            'cell' => [
                'id' => $cell->id,
                'coverageAreaId' => $cell->coverage_area_id,
                'h3' => $cell->h3(),
                'mandate' => $cell->coverageArea->name ?? '',
                'centre' => [(float) $centre->lon, (float) $centre->lat],
            ],
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'minMappingUnitHa' => $campaign->min_mapping_unit_ha === null ? null : (float) $campaign->min_mapping_unit_ha,
                'maxAccuracyM' => $campaign->field_max_accuracy_m ?? $cell->coverageArea->accuracy_threshold_m ?? 15,
                'buildings' => $campaign->captures(CaptureMode::Buildings),
            ],
            'classes' => $classes->map(static fn (FeatureClass $class): array => [
                'id' => $class->id,
                'key' => $class->key,
                'label' => $class->label,
                'geometryType' => $class->geometry_type->value,
                'style' => $class->style,
                'version' => $class->latestVersion?->version,
                'attributes' => $class->latestVersion->attribute_schema ?? [],
            ])->values()->all(),
            'features' => $this->featuresAround($cell->coverage_area_id, $request->user()),
            'tasks' => $this->tasksFor($request->user(), $campaign->id),
        ]);
    }

    /**
     * A photograph of an area capture. Refused (409) until the capture itself
     * has landed, so the phone holds it and tries again.
     */
    public function storePhotograph(Request $request, StorePhotograph $storer): JsonResponse
    {
        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'revision_client_uuid' => ['required', 'uuid'],
            'photo' => ['required', 'file', 'image', 'max:4096'],
            'device_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'device_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'bearing' => ['nullable', 'numeric', 'between:0,360'],
        ]);

        $officer = $request->user();
        abort_unless($officer instanceof User, 403);

        $revision = AreaFeatureRevision::query()->where('client_uuid', $data['revision_client_uuid'])->first();

        if ($revision === null) {
            return new JsonResponse(['message' => 'The capture has not arrived yet.'], 409);
        }

        abort_unless($revision->captured_by === $officer->id, 403);

        try {
            $media = $storer->store(
                $request->file('photo'),
                $revision,
                'area_photo',
                $officer,
                (string) $data['client_uuid'],
                isset($data['device_longitude']) ? (float) $data['device_longitude'] : null,
                isset($data['device_latitude']) ? (float) $data['device_latitude'] : null,
                $revision->field_session_id,
            );
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        if (isset($data['bearing']) && $media->wasRecentlyCreated) {
            DB::update('UPDATE media SET bearing_deg = ? WHERE id = ?', [round((float) $data['bearing'], 1), $media->id]);
        }

        return new JsonResponse(['id' => $media->id, 'client_uuid' => $media->client_uuid], 201);
    }

    /**
     * What is already drawn in and around the officer's cells: shown on the
     * map, and the vertices a new drawing can snap to.
     *
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function featuresAround(int $coverageAreaId, mixed $officer): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT f.client_uuid, fc.key, fc.label, fc.style, f.verification_status, r.capture_method,
                   ST_AsGeoJSON(r.geom, 7) AS geometry
              FROM area_features f
              JOIN area_feature_revisions r ON r.id = f.current_revision_id
              JOIN feature_classes fc ON fc.id = f.feature_class_id
             WHERE f.coverage_area_id = ?
               AND f.status = 'live'
               AND r.geom && (
                   SELECT ST_Expand(ST_Extent(g.boundary), 0.01)
                     FROM assignments a JOIN grid_cells g ON g.id = a.grid_cell_id
                    WHERE a.user_id = ? AND a.closed_at IS NULL
               )
             LIMIT 2000
        SQL, [$coverageAreaId, $officer instanceof User ? $officer->id : 0]);

        return [
            'type' => 'FeatureCollection',
            'features' => array_map(static fn (object $row): array => [
                'type' => 'Feature',
                'geometry' => json_decode((string) $row->geometry, true),
                'properties' => [
                    'uuid' => $row->client_uuid,
                    'class' => $row->key,
                    'label' => $row->label,
                    'colour' => json_decode((string) ($row->style ?? '{}'), true)['fill'] ?? json_decode((string) ($row->style ?? '{}'), true)['stroke'] ?? '#4BB8B0',
                    'verification' => $row->verification_status,
                    'method' => $row->capture_method,
                ],
            ], $rows),
        ];
    }

    /**
     * The features this officer was sent to check, with the shape drawn at the
     * desk so they can confirm it or correct it on the ground.
     *
     * @return list<array<string, mixed>>
     */
    private function tasksFor(mixed $officer, int $campaignId): array
    {
        if (! $officer instanceof User) {
            return [];
        }

        $rows = DB::select(<<<'SQL'
            SELECT t.id, f.client_uuid, f.feature_class_id, fc.label, r.capture_method,
                   ST_AsGeoJSON(r.geom, 7) AS geometry,
                   ST_X(ST_PointOnSurface(r.geom)) AS lon, ST_Y(ST_PointOnSurface(r.geom)) AS lat
              FROM area_verification_tasks t
              JOIN area_features f ON f.id = t.area_feature_id
              JOIN area_feature_revisions r ON r.id = f.current_revision_id
              JOIN feature_classes fc ON fc.id = f.feature_class_id
             WHERE t.status = 'open' AND t.assigned_to = ? AND f.campaign_id = ? AND f.status = 'live'
             ORDER BY t.id
             LIMIT 200
        SQL, [$officer->id, $campaignId]);

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'featureUuid' => (string) $row->client_uuid,
            'classId' => (int) $row->feature_class_id,
            'label' => (string) $row->label,
            'method' => (string) $row->capture_method,
            'geometry' => json_decode((string) $row->geometry, true),
            'at' => [(float) $row->lon, (float) $row->lat],
        ], $rows);
    }
}
