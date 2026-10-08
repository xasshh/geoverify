<?php

declare(strict_types=1);

namespace App\Http\Controllers\Desk;

use App\Domain\AreaCapture\Actions\CaptureAreaFeature;
use App\Domain\AreaCapture\Actions\GenerateVerificationTasks;
use App\Domain\AreaCapture\Actions\ImportAreaFeatures;
use App\Domain\AreaCapture\Jobs\SeedFromLandCover;
use App\Domain\AreaCapture\Models\AreaFeature;
use App\Domain\AreaCapture\Models\AreaFeatureBatch;
use App\Domain\AreaCapture\Models\AreaFeatureRevision;
use App\Domain\Campaign\Enums\CaptureMode;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\FeatureClass;
use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Coverage\Models\MapPack;
use App\Domain\Imagery\Models\BasemapLayer;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * The desk: drawing the land over imagery, importing what clients send, and
 * pre-drawing land cover, for officers to then check on the ground.
 *
 * Every shape from here goes through CaptureAreaFeature on its desk channel,
 * so it meets the same rules as a shape an officer draws in the field.
 */
final class DeskController
{
    /** The campaigns that map land, and their ground. */
    public function index(): Response
    {
        $campaigns = Campaign::query()
            ->whereRaw("capture_modes @> '[\"area_features\"]'::jsonb")
            ->with(['coverageAreas' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        $counts = DB::table('area_features as f')
            ->join('area_feature_revisions as r', 'r.id', '=', 'f.current_revision_id')
            ->where('f.status', AreaFeature::STATUS_LIVE)
            ->groupBy('f.coverage_area_id')
            ->selectRaw("f.coverage_area_id,
                count(*) AS total,
                count(*) FILTER (WHERE f.verification_status = 'unverified') AS unverified,
                count(*) FILTER (WHERE r.capture_method IN ('desk_digitised', 'imported')) AS from_desk")
            ->get()
            ->keyBy('coverage_area_id');

        $openTasks = DB::table('area_verification_tasks as t')
            ->join('area_features as f', 'f.id', '=', 't.area_feature_id')
            ->where('t.status', 'open')
            ->groupBy('f.campaign_id')
            ->selectRaw('f.campaign_id, count(*) AS n')
            ->pluck('n', 'campaign_id');

        return Inertia::render('desk/Index', [
            'campaigns' => $campaigns->map(static fn (Campaign $campaign): array => [
                'id' => $campaign->id,
                'code' => $campaign->code,
                'name' => $campaign->name,
                'samplePct' => $campaign->verification_sample_pct,
                'openTasks' => (int) ($openTasks[$campaign->id] ?? 0),
                'mandates' => $campaign->coverageAreas->map(static fn (CoverageArea $area): array => [
                    'id' => $area->id,
                    'name' => $area->name,
                    'features' => (int) ($counts[$area->id]->total ?? 0),
                    'unverified' => (int) ($counts[$area->id]->unverified ?? 0),
                    'fromDesk' => (int) ($counts[$area->id]->from_desk ?? 0),
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    /** One mandate, full screen, to draw on. */
    public function show(CoverageArea $area): Response
    {
        $campaign = $this->campaignOf($area);

        $centre = DB::selectOne(
            'SELECT ST_X(ST_Centroid(boundary)) AS lon, ST_Y(ST_Centroid(boundary)) AS lat FROM coverage_areas WHERE id = ?',
            [$area->id],
        );

        $pack = MapPack::query()->where('coverage_area_id', $area->id)->current()->first();
        $imagery = BasemapLayer::query()->where('coverage_area_id', $area->id)->current()->latest('built_at')->first();

        $classes = FeatureClass::query()
            ->where('campaign_id', $campaign->id)
            ->where('is_active', true)
            ->with('latestVersion')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('desk/Mandate', [
            'area' => ['id' => $area->id, 'name' => $area->name, 'centre' => [(float) $centre->lon, (float) $centre->lat]],
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'minMappingUnitHa' => $campaign->min_mapping_unit_ha === null ? null : (float) $campaign->min_mapping_unit_ha,
                'samplePct' => $campaign->verification_sample_pct,
            ],
            'pack' => $pack === null ? null : [
                'url' => route('desk.pack', $area),
                'minZoom' => $pack->min_zoom,
                'maxZoom' => $pack->max_zoom,
                'layers' => $pack->layer_counts,
            ],
            'imagery' => $imagery === null ? null : [
                'url' => route('desk.imagery', $area),
                'captured' => $imagery->capturedLabel(),
                'licence' => $imagery->licence_note,
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
            'batches' => AreaFeatureBatch::query()
                ->where('coverage_area_id', $area->id)
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(static fn (AreaFeatureBatch $batch): array => [
                    'id' => $batch->id,
                    'kind' => $batch->kind,
                    'status' => $batch->status,
                    'source' => $batch->source_name,
                    'created' => $batch->created_count,
                    'refused' => $batch->refused_count,
                    'refusals' => array_slice($batch->refusals ?? [], 0, 5),
                    'error' => $batch->error,
                    'at' => $batch->created_at->toIso8601String(),
                ])->values()->all(),
        ]);
    }

    /** What is recorded on this ground, as GeoJSON, fetched by the map. */
    public function features(CoverageArea $area): JsonResponse
    {
        $this->campaignOf($area);

        $rows = DB::select(<<<'SQL'
            SELECT f.client_uuid, fc.label, fc.style, f.verification_status, r.capture_method,
                   r.area_ha, r.length_m, ST_AsGeoJSON(r.geom, 7) AS geometry,
                   EXISTS (SELECT 1 FROM area_verification_tasks t WHERE t.area_feature_id = f.id AND t.status = 'open') AS sent
              FROM area_features f
              JOIN area_feature_revisions r ON r.id = f.current_revision_id
              JOIN feature_classes fc ON fc.id = f.feature_class_id
             WHERE f.coverage_area_id = ? AND f.status = 'live'
             LIMIT 25000
        SQL, [$area->id]);

        return new JsonResponse([
            'type' => 'FeatureCollection',
            'features' => array_map(static function (object $row): array {
                $style = json_decode((string) ($row->style ?? '{}'), true);

                return [
                    'type' => 'Feature',
                    'geometry' => json_decode((string) $row->geometry, true),
                    'properties' => [
                        'uuid' => $row->client_uuid,
                        'label' => $row->label,
                        'colour' => $style['fill'] ?? $style['stroke'] ?? '#4BB8B0',
                        'verification' => $row->verification_status,
                        'method' => $row->capture_method,
                        'areaHa' => $row->area_ha === null ? null : (float) $row->area_ha,
                        'lengthM' => $row->length_m === null ? null : (float) $row->length_m,
                        'sent' => (bool) $row->sent,
                    ],
                ];
            }, $rows),
        ]);
    }

    public function store(Request $request, CoverageArea $area, CaptureAreaFeature $capture): RedirectResponse
    {
        $this->campaignOf($area);

        $data = $request->validate([
            'feature_class_id' => ['required', 'integer'],
            'class_version' => ['required', 'integer'],
            'geometry' => ['required', 'array'],
            'answers' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $revision = $capture([
            ...$data,
            'client_uuid' => (string) Str::uuid7(),
            'feature_uuid' => (string) Str::uuid7(),
            'capture_method' => AreaFeatureRevision::METHOD_DESK,
            'coverage_area_id' => $area->id,
            'answers' => $data['answers'] ?? [],
            'basemap_layer_id' => BasemapLayer::query()->where('coverage_area_id', $area->id)->current()->value('id'),
        ], $this->actor(), CaptureAreaFeature::DESK);

        return back()->with('status', sprintf(
            'Saved%s. It waits for an officer to check it.',
            $revision->area_ha !== null ? ', '.number_format((float) $revision->area_ha, 2).' ha' : '',
        ));
    }

    /** Withdrawn, never deleted: the feature and every revision stay. */
    public function withdraw(Request $request, AreaFeature $feature): RedirectResponse
    {
        $this->campaignOf(CoverageArea::query()->findOrFail($feature->coverage_area_id));

        $feature->forceFill(['status' => AreaFeature::STATUS_WITHDRAWN])->save();

        DB::table('area_verification_tasks')
            ->where('area_feature_id', $feature->id)
            ->where('status', 'open')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        VerificationEvent::record($feature, 'area_feature.withdrawn', $this->actor(), array_filter([
            'reason' => is_string($request->input('reason')) ? mb_substr($request->input('reason'), 0, 300) : null,
        ]));

        return back()->with('status', 'Withdrawn. It stays on record, off the map.');
    }

    public function previewImport(Request $request, CoverageArea $area, ImportAreaFeatures $import): JsonResponse
    {
        $this->campaignOf($area);

        $request->validate(['file' => ['required', 'file', 'max:51200']]);

        $preview = $import->preview($area, $request->file('file'), $this->actor());

        return new JsonResponse([
            'batchId' => $preview['batch']->id,
            'total' => $preview['total'],
            'geometry' => $preview['geometry'],
            'properties' => $preview['properties'],
        ]);
    }

    public function commitImport(Request $request, AreaFeatureBatch $batch, ImportAreaFeatures $import): RedirectResponse
    {
        $this->campaignOf(CoverageArea::query()->findOrFail($batch->coverage_area_id));

        $mapping = $request->validate([
            'class_id' => ['nullable', 'integer'],
            'property' => ['nullable', 'string', 'max:120'],
            'values' => ['nullable', 'array'],
            'values.*' => ['nullable', 'integer'],
        ]);

        if (($mapping['class_id'] ?? null) === null && ($mapping['property'] ?? null) === null) {
            throw ValidationException::withMessages(['class_id' => 'Choose a class for every shape, or a property to map.']);
        }

        $result = $import->commit($batch, $mapping, $this->actor());

        return back()->with('status', $result->status === 'done'
            ? "{$result->created_count} features imported.".($result->error !== null ? " {$result->error}" : '')
            : (string) $result->error);
    }

    public function seedLandCover(CoverageArea $area): RedirectResponse
    {
        $this->campaignOf($area);

        if (AreaFeatureBatch::query()->where('coverage_area_id', $area->id)->whereIn('status', ['queued', 'processing'])->exists()) {
            throw ValidationException::withMessages(['seed' => 'An import for this ground is already running.']);
        }

        $batch = AreaFeatureBatch::query()->create([
            'coverage_area_id' => $area->id,
            'kind' => AreaFeatureBatch::KIND_LANDCOVER,
            'status' => 'queued',
            'source_name' => 'ESA WorldCover 10 m 2021',
            'requested_by' => $this->actor()->id,
        ]);

        SeedFromLandCover::dispatch($batch->id)
            ->onConnection((string) config('geoverify.imagery.queue_connection'))
            ->onQueue('imagery');

        return back()->with('status', 'Drawing the land cover from ESA WorldCover. This takes a few minutes; it appears here when done.');
    }

    public function sendForVerification(Campaign $campaign, GenerateVerificationTasks $generate): RedirectResponse
    {
        abort_unless($campaign->captures(CaptureMode::AreaFeatures), 404);

        $result = $generate($campaign, $this->actor());

        return back()->with('status', $result['sampled'] === 0
            ? 'Nothing more to send: the sample for this campaign is already out.'
            : sprintf(
                '%d features sent for checking%s.',
                $result['sampled'],
                $result['unassigned'] > 0 ? ", {$result['unassigned']} waiting for an officer to be in the field" : '',
            ));
    }

    /** The street pack, read by range by the desk map. */
    public function pack(CoverageArea $area): BinaryFileResponse|SymfonyRedirect
    {
        $this->campaignOf($area);
        $pack = MapPack::query()->where('coverage_area_id', $area->id)->current()->firstOrFail();

        return $this->archive($pack->path, $pack->checksum);
    }

    /** The satellite image, read by range by the desk map. */
    public function imagery(CoverageArea $area): BinaryFileResponse|SymfonyRedirect
    {
        $this->campaignOf($area);
        $layer = BasemapLayer::query()->where('coverage_area_id', $area->id)->current()->latest('built_at')->firstOrFail();

        return $this->archive((string) $layer->path, (string) $layer->checksum);
    }

    private function archive(string $path, string $checksum): BinaryFileResponse|SymfonyRedirect
    {
        $disk = Storage::disk('media');

        if (config('filesystems.disks.media.driver') !== 'local') {
            return redirect()->away($disk->temporaryUrl($path, now()->addHour()));
        }

        return response()->file($disk->path($path), [
            'Content-Type' => 'application/vnd.pmtiles',
            'ETag' => '"'.$checksum.'"',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function campaignOf(CoverageArea $area): Campaign
    {
        $campaign = $area->campaign_id === null ? null : Campaign::query()->find($area->campaign_id);
        abort_if($campaign === null || ! $campaign->captures(CaptureMode::AreaFeatures), 404);

        return $campaign;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
