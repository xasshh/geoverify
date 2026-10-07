<?php

declare(strict_types=1);

namespace App\Domain\Imagery\Actions;

use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Imagery\Jobs\BuildSatelliteBasemap;
use App\Domain\Imagery\Models\BasemapLayer;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asks for satellite imagery of a mandate to be built.
 *
 * The work itself is long (it reads a few hundred megabytes of imagery by
 * range and cuts it into tiles), so it runs on the imagery queue; this only
 * checks it is worth doing and records that it was asked for.
 */
final class RequestSatelliteBasemap
{
    public function __invoke(CoverageArea $area, ?User $actor): BasemapLayer
    {
        $inFlight = BasemapLayer::query()
            ->where('coverage_area_id', $area->id)
            ->whereIn('status', [BasemapLayer::STATUS_QUEUED, BasemapLayer::STATUS_PROCESSING])
            ->exists();

        if ($inFlight) {
            throw ValidationException::withMessages([
                'imagery' => "Imagery for {$area->name} is already being built.",
            ]);
        }

        $km2 = (float) DB::scalar(
            'SELECT ST_Area(boundary::geography) / 1e6 FROM coverage_areas WHERE id = ?',
            [$area->id],
        );

        $limit = (int) config('geoverify.imagery.max_area_km2');

        if ($km2 > $limit) {
            throw ValidationException::withMessages([
                'imagery' => sprintf(
                    '%s is %s km2, more than the %s km2 a phone can carry as one image. Split it into smaller mandates.',
                    $area->name,
                    number_format($km2),
                    number_format($limit),
                ),
            ]);
        }

        return DB::transaction(function () use ($area, $actor, $km2): BasemapLayer {
            $layer = BasemapLayer::query()->create([
                'coverage_area_id' => $area->id,
                'name' => "Sentinel-2, {$area->name}",
                'source' => BasemapLayer::SOURCE_SENTINEL2,
                'status' => BasemapLayer::STATUS_QUEUED,
                'stage' => 'Waiting to start',
                'resolution_cm' => 1000,
                'licence_note' => (string) config('geoverify.imagery.licence_note'),
                'requested_by' => $actor?->id,
            ]);

            VerificationEvent::record($layer, 'imagery.requested', $actor, [
                'coverage_area_id' => $area->id,
                'area_km2' => round($km2, 1),
            ]);

            BuildSatelliteBasemap::dispatch($layer->id)
                ->onConnection((string) config('geoverify.imagery.queue_connection'))
                ->onQueue('imagery');

            return $layer;
        });
    }
}
