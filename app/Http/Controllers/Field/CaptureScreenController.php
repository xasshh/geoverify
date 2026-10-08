<?php

declare(strict_types=1);

namespace App\Http\Controllers\Field;

use App\Domain\Campaign\Enums\CaptureMode;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Field\Models\Assignment;
use App\Domain\Registry\Enums\OccupancyStatus;
use App\Domain\Registry\Enums\StructureType;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** The capture screen for one assigned cell. */
final class CaptureScreenController
{
    /**
     * The consent script, versioned.
     *
     * Scripts change, and consent given in March under one wording is not consent
     * to whatever the wording says in December, so the version travels with every
     * record rather than being assumed.
     */
    private const CONSENT_VERSION = '2026-08-en-1';

    private const CONSENT_TEXT =
        'I am recording businesses in this area for the Nigeria Business Directory. '.
        'I will note what your business is called, what it does and where it is. '.
        'You can decline, and you can ask for your entry to be removed later. '.
        'May I record this business?';

    public function show(Request $request, Assignment $assignment): Response
    {
        Gate::authorize('start', $assignment);

        $cell = $assignment->gridCell;
        abort_if($cell === null, 404);

        /** @var object{lon: float, lat: float}|null $centre */
        $centre = DB::selectOne(
            'select ST_X(centroid::geometry) as lon, ST_Y(centroid::geometry) as lat
               from grid_cells where id = ?',
            [$cell->id],
        );

        // The way across to area capture, offered only where the campaign
        // asks for it; every other campaign's screen is exactly as before.
        $campaign = Campaign::query()->find($cell->coverageArea?->campaign_id);
        $areaCaptureUrl = $campaign !== null && $campaign->captures(CaptureMode::AreaFeatures)
            ? route('field.area', $assignment)
            : null;

        return Inertia::render('field/Capture', [
            'areaCaptureUrl' => $areaCaptureUrl,
            'assignmentId' => $assignment->id,
            'cell' => [
                'id' => $cell->id,
                'coverageAreaId' => $cell->coverage_area_id,
                'h3' => $cell->h3(),
                'mandate' => $cell->coverageArea->name ?? '',
                'footprints' => $cell->footprint_count,
                'captured' => $cell->structures_captured,
                'centre' => [(float) ($centre->lon ?? 0), (float) ($centre->lat ?? 0)],
            ],
            'structureTypes' => StructureType::options(),
            'occupancyStatuses' => OccupancyStatus::options(),
            'structures' => $this->structuresIn($cell->id),
            'consentScript' => [
                'version' => self::CONSENT_VERSION,
                'text' => self::CONSENT_TEXT,
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function structuresIn(int $gridCellId): array
    {
        $structures = Structure::query()
            ->with(['enterprises:id,structure_id,unit_label,floor,trading_name,sector_code', 'observations'])
            ->where('grid_cell_id', $gridCellId)
            ->orderByDesc('captured_at')
            ->limit(200)
            ->get();

        return $structures->map(static function (Structure $s): array {
            // The visit before this one, which the sheet shows as read only
            // history. Never the current one: that is what is being edited.
            $prior = $s->observations->skip(1)->first();

            return [
                'id' => $s->id,
                'clientUuid' => $s->client_uuid,
                'structureType' => $s->structure_type,
                'unitCount' => $s->unit_count,
                'floors' => $s->floors,
                'occupancyStatus' => $s->occupancy_status,
                'resolvedWard' => null,
                'enterprises' => $s->enterprises->map(static fn (Enterprise $e): array => [
                    'id' => $e->id,
                    'unitLabel' => $e->unit_label,
                    'floor' => $e->floor,
                    'tradingName' => $e->trading_name,
                    'sectorCode' => $e->sector_code,
                ])->values()->all(),
                'priorObservation' => $prior === null ? null : [
                    'observedOn' => $prior->observed_at->toDateString(),
                    'summary' => sprintf(
                        '%s, %s units, %s floors.',
                        ucfirst(str_replace('_', ' ', $prior->occupancy_status)),
                        $prior->unit_count ?? 0,
                        $prior->floors ?? 0,
                    ),
                ],
            ];
        })->values()->all();
    }
}
