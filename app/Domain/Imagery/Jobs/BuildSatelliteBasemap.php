<?php

declare(strict_types=1);

namespace App\Domain\Imagery\Jobs;

use App\Domain\Imagery\ImageryPipeline;
use App\Domain\Imagery\Models\BasemapLayer;
use App\Domain\Imagery\PmtilesHeader;
use App\Domain\Imagery\SentinelCatalogue;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Builds a mandate's satellite imagery and makes it the current one.
 *
 * Tried once. A failure is recorded on the layer with the reason, for the
 * administrator to read and ask again; retrying a forty-minute build on its
 * own would only hide the reason.
 */
final class BuildSatelliteBasemap implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly int $layerId)
    {
        $this->timeout = (int) config('geoverify.imagery.timeout_seconds');
    }

    public function handle(SentinelCatalogue $catalogue, ImageryPipeline $pipeline): void
    {
        $layer = BasemapLayer::query()->with('coverageArea')->find($this->layerId);

        if ($layer === null || $layer->status === BasemapLayer::STATUS_READY) {
            return;
        }

        $stage = static function (string $what, int $percent) use ($layer): void {
            $layer->forceFill(['stage' => $what, 'progress' => $percent])->save();
        };

        $layer->forceFill(['status' => BasemapLayer::STATUS_PROCESSING, 'error' => null])->save();
        $stage('Finding the clearest images', 5);

        // The boundary simplified to about 20 m for the catalogue search (a
        // request body, not a survey), and exact for the clip.
        $boundary = (string) DB::scalar(
            'SELECT ST_AsGeoJSON(boundary) FROM coverage_areas WHERE id = ?',
            [$layer->coverage_area_id],
        );
        $searchShape = (string) DB::scalar(
            'SELECT ST_AsGeoJSON(ST_SimplifyPreserveTopology(boundary, 0.0002)) FROM coverage_areas WHERE id = ?',
            [$layer->coverage_area_id],
        );

        /** @var array<string, mixed> $intersects */
        $intersects = json_decode($searchShape, true, flags: JSON_THROW_ON_ERROR);
        $scenes = $catalogue->clearestScenes($intersects);

        if ($scenes === []) {
            throw new RuntimeException('No clear Sentinel-2 image of this ground was found in the last two years.');
        }

        $layer->forceFill([
            'scenes' => array_map(static fn (array $s): array => [
                'id' => $s['id'], 'tile' => $s['tile'], 'date' => $s['date'], 'cloud' => $s['cloud'],
            ], $scenes),
            'captured_from' => min(array_column($scenes, 'date')),
            'captured_to' => max(array_column($scenes, 'date')),
            'cloud_pct' => round(max(array_column($scenes, 'cloud')), 2),
        ])->save();

        $work = storage_path('app/imagery-work/'.Str::lower((string) Str::ulid()));
        File::ensureDirectoryExists($work);

        try {
            $collection = json_encode([
                'type' => 'FeatureCollection',
                'features' => [['type' => 'Feature', 'properties' => new \stdClass, 'geometry' => json_decode($boundary)]],
            ], JSON_THROW_ON_ERROR);

            $built = $pipeline->build($scenes, $collection, $work, $stage);
            $header = PmtilesHeader::read($built);

            if (! in_array($header['tile_type'], [PmtilesHeader::TILE_TYPE_WEBP, PmtilesHeader::TILE_TYPE_PNG, PmtilesHeader::TILE_TYPE_JPEG], true)) {
                throw new RuntimeException('The archive holds no raster tiles.');
            }

            $stage('Saving', 95);
            $target = "packs/imagery-{$layer->coverage_area_id}/".Str::lower((string) Str::ulid()).'.pmtiles';
            $stream = fopen($built, 'rb');

            if ($stream === false) {
                throw new RuntimeException('The built archive could not be read back.');
            }

            Storage::disk('media')->put($target, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $bytes = (int) filesize($built);
            $checksum = (string) hash_file('sha256', $built);
        } finally {
            File::deleteDirectory($work);
        }

        DB::transaction(function () use ($layer, $target, $bytes, $checksum, $header): void {
            // The one it replaces stays, marked superseded: a feature records
            // the imagery it was drawn over.
            BasemapLayer::query()
                ->where('coverage_area_id', $layer->coverage_area_id)
                ->where('source', $layer->source)
                ->whereKeyNot($layer->id)
                ->current()
                ->update(['superseded_at' => now()]);

            $layer->forceFill([
                'status' => BasemapLayer::STATUS_READY,
                'progress' => 100,
                'stage' => null,
                'path' => $target,
                'bytes' => $bytes,
                'checksum' => $checksum,
                'min_zoom' => $header['min_zoom'],
                'max_zoom' => $header['max_zoom'],
                'west' => $header['west'],
                'south' => $header['south'],
                'east' => $header['east'],
                'north' => $header['north'],
                'built_at' => now(),
            ])->save();

            VerificationEvent::record($layer, 'imagery.built', null, [
                'bytes' => $bytes,
                'zoom' => [$header['min_zoom'], $header['max_zoom']],
                'scenes' => array_column($layer->scenes ?? [], 'id'),
            ], VerificationEvent::ACTOR_SYSTEM);
        });
    }

    public function failed(?Throwable $e): void
    {
        $layer = BasemapLayer::query()->find($this->layerId);

        if ($layer === null) {
            return;
        }

        $layer->forceFill([
            'status' => BasemapLayer::STATUS_FAILED,
            'stage' => null,
            'error' => Str::limit($e?->getMessage() ?? 'The build stopped without saying why.', 900),
        ])->save();

        VerificationEvent::record($layer, 'imagery.failed', null, [
            'error' => Str::limit($e?->getMessage() ?? '', 300),
        ], VerificationEvent::ACTOR_SYSTEM);
    }
}
