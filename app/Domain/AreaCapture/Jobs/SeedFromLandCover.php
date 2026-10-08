<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Jobs;

use App\Domain\AreaCapture\Actions\CaptureAreaFeature;
use App\Domain\AreaCapture\LandCoverPipeline;
use App\Domain\AreaCapture\Models\AreaFeatureBatch;
use App\Domain\AreaCapture\Models\AreaFeatureRevision;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\FeatureClass;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pre-draws a mandate's land cover from ESA WorldCover, for officers to check.
 *
 * Every polygon goes through CaptureAreaFeature as an import, so the same
 * rules apply as to anything drawn by hand; one that is refused (it overlaps
 * something an officer already recorded, say) is counted and skipped, not
 * forced in. The officer who later verifies a feature answers its questions.
 */
final class SeedFromLandCover implements ShouldQueue
{
    use Queueable;

    /** WorldCover class value to feature class key. 70 (snow), 95 (mangrove) and 100 (moss) do not occur here. */
    public const CLASSES = [
        10 => 'forest_woodland',
        20 => 'grassland_savanna',
        30 => 'grassland_savanna',
        40 => 'farmland',
        50 => 'settlement_cluster',
        60 => 'bare_land_rock',
        80 => 'water_body',
        90 => 'wetland',
    ];

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly int $batchId)
    {
        $this->timeout = (int) config('geoverify.imagery.timeout_seconds');
    }

    public function handle(LandCoverPipeline $pipeline, CaptureAreaFeature $capture): void
    {
        $batch = AreaFeatureBatch::query()->find($this->batchId);

        if ($batch === null || $batch->status !== 'queued') {
            return;
        }

        $batch->forceFill(['status' => 'processing'])->save();

        $area = $batch->coverageArea;
        $campaign = Campaign::query()->findOrFail($area->campaign_id);
        $actor = User::query()->findOrFail($batch->requested_by);

        $classes = FeatureClass::query()
            ->where('campaign_id', $campaign->id)
            ->where('is_active', true)
            ->whereIn('key', array_unique(array_values(self::CLASSES)))
            ->with('latestVersion')
            ->get()
            ->keyBy('key');

        $shape = DB::selectOne(
            'SELECT ST_AsGeoJSON(boundary) AS g, ST_XMin(boundary) AS west, ST_YMin(boundary) AS south,
                    ST_XMax(boundary) AS east, ST_YMax(boundary) AS north
               FROM coverage_areas WHERE id = ?',
            [$area->id],
        );

        // A 10 m pixel is 0.01 ha, so the minimum mapping unit in pixels.
        $minimumPixels = (int) ceil(((float) ($campaign->min_mapping_unit_ha ?? 0.5)) * 100);

        $work = storage_path('app/landcover-work/'.Str::lower((string) Str::ulid()));
        File::ensureDirectoryExists($work);

        $created = 0;
        $refusals = [];

        try {
            $collection = json_encode([
                'type' => 'FeatureCollection',
                'features' => [['type' => 'Feature', 'properties' => new \stdClass, 'geometry' => json_decode((string) $shape->g)]],
            ], JSON_THROW_ON_ERROR);

            $path = $pipeline->build($collection, [
                'west' => (float) $shape->west, 'south' => (float) $shape->south,
                'east' => (float) $shape->east, 'north' => (float) $shape->north,
            ], $minimumPixels, $work);

            /** @var array{features: list<array<string, mixed>>} $cover */
            $cover = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            foreach ($cover['features'] as $index => $feature) {
                $key = self::CLASSES[(int) ($feature['properties']['class'] ?? 0)] ?? null;
                $class = $key === null ? null : $classes->get($key);

                if ($class === null) {
                    continue;
                }

                try {
                    DB::transaction(fn () => $capture([
                        'client_uuid' => (string) Str::uuid7(),
                        'feature_uuid' => (string) Str::uuid7(),
                        'feature_class_id' => $class->id,
                        'class_version' => $class->latestVersion?->version,
                        'capture_method' => AreaFeatureRevision::METHOD_IMPORTED,
                        'coverage_area_id' => $area->id,
                        'geometry' => $feature['geometry'] ?? null,
                        'answers' => [],
                        'area_feature_batch_id' => $batch->id,
                    ], $actor, CaptureAreaFeature::DESK));
                    $created++;
                } catch (ValidationException $e) {
                    if (count($refusals) < 50) {
                        $refusals[] = ['index' => $index, 'message' => (string) collect($e->errors())->flatten()->first()];
                    }
                }
            }
        } finally {
            File::deleteDirectory($work);
        }

        $batch->forceFill([
            'status' => 'done',
            'created_count' => $created,
            'refused_count' => count($refusals),
            'refusals' => $refusals === [] ? null : $refusals,
            'finished_at' => now(),
        ])->save();

        VerificationEvent::record($batch, 'area_features.seeded', null, [
            'coverage_area_id' => $area->id,
            'created' => $created,
            'refused' => count($refusals),
            'source' => 'ESA WorldCover 10 m 2021 (CC BY 4.0)',
        ], VerificationEvent::ACTOR_SYSTEM);
    }

    public function failed(?Throwable $e): void
    {
        AreaFeatureBatch::query()->whereKey($this->batchId)->update([
            'status' => 'failed',
            'error' => Str::limit($e?->getMessage() ?? 'The seed stopped without saying why.', 500),
            'finished_at' => now(),
        ]);
    }
}
