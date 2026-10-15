<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domain\Campaign\Enums\CaptureMode;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\ClientUser;
use App\Domain\Election\Actions\ExportPollingUnits;
use App\Domain\Election\Actions\SummarisePollingUnits;
use App\Domain\Election\Models\PollingUnit;
use App\Domain\Election\Models\PollingUnitCheck;
use App\Domain\Media\Models\Media;
use App\Domain\Verification\Models\VerificationEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A campaign's polling units, as its client sees them: the summary, a map of
 * every unit, the findings and the exports.
 *
 * Only what a supervisor accepted is a finding here; a filed check nobody has
 * read is shown as "being checked". No officer is named, and the photographs
 * are the site and the way in, asked for by name (PollingUnitCheck::clientPhotos).
 */
final class PollingUnitController extends Controller
{
    public function show(Campaign $campaign, SummarisePollingUnits $summarise): Response
    {
        $this->authorise($campaign);

        $bounds = DB::selectOne(<<<'SQL'
            SELECT ST_XMin(e) AS w, ST_YMin(e) AS s, ST_XMax(e) AS e, ST_YMax(e) AS n
              FROM (SELECT ST_Extent(COALESCE(confirmed_point, listed_point)::geometry) AS e FROM polling_units WHERE campaign_id = ?) x
        SQL, [$campaign->id]);

        return Inertia::render('client/CampaignPollingUnits', [
            'campaign' => ['id' => $campaign->id, 'code' => $campaign->code, 'name' => $campaign->name],
            'summary' => $summarise($campaign),
            'bounds' => $bounds?->w === null ? null : [(float) $bounds->w, (float) $bounds->s, (float) $bounds->e, (float) $bounds->n],
        ]);
    }

    /** Every unit with a position, for the map: where it was confirmed, or where the list puts it. */
    public function points(Campaign $campaign): JsonResponse
    {
        $this->authorise($campaign);

        $rows = DB::select(<<<'SQL'
            SELECT u.id, u.inec_code, u.name, u.status,
                   ST_X(COALESCE(u.confirmed_point, u.listed_point)::geometry) AS lng,
                   ST_Y(COALESCE(u.confirmed_point, u.listed_point)::geometry) AS lat,
                   (u.status = 'confirmed' AND (c.found = 'elsewhere' OR u.listed_distance_m > ?)) AS moved
              FROM polling_units u
              LEFT JOIN polling_unit_checks c ON c.id = u.settled_by_check_id
             WHERE u.campaign_id = ? AND COALESCE(u.confirmed_point, u.listed_point) IS NOT NULL
        SQL, [PollingUnitCheck::AT_LISTED_M, $campaign->id]);

        // The LGAs under the units, outlined for context, simplified for a screen.
        $lgas = DB::select(<<<'SQL'
            SELECT b.name, ST_AsGeoJSON(ST_SimplifyPreserveTopology(b.boundary, 0.002), 5) AS geometry
              FROM admin_boundaries b
             WHERE b.level = 'lga'
               AND b.boundary && (SELECT ST_Extent(COALESCE(confirmed_point, listed_point)::geometry) FROM polling_units WHERE campaign_id = ?)
        SQL, [$campaign->id]);

        return new JsonResponse([
            'lgas' => [
                'type' => 'FeatureCollection',
                'features' => array_map(static fn (object $l): array => [
                    'type' => 'Feature',
                    'geometry' => json_decode((string) $l->geometry, true),
                    'properties' => ['name' => $l->name],
                ], $lgas),
            ],
            'type' => 'FeatureCollection',
            'features' => array_map(static fn (object $r): array => [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [round((float) $r->lng, 6), round((float) $r->lat, 6)]],
                'properties' => [
                    'id' => (int) $r->id,
                    'code' => $r->inec_code,
                    'name' => $r->name,
                    // What the client may know: settled or not, and how.
                    'state' => match ($r->status) {
                        PollingUnit::CONFIRMED => (bool) $r->moved ? 'moved' : 'confirmed',
                        PollingUnit::NOT_FOUND => 'not_found',
                        default => 'pending',
                    },
                ],
            ], $rows),
        ], headers: ['Cache-Control' => 'private, max-age=60']);
    }

    /** One unit's settled check: the answers and the client's photographs. */
    public function unit(Campaign $campaign, int $unit): JsonResponse
    {
        $this->authorise($campaign);

        $row = PollingUnit::query()->where('campaign_id', $campaign->id)->with(['lga', 'ward'])->findOrFail($unit);
        $check = $row->settledBy()->first();
        $settled = $check !== null && in_array($row->status, [PollingUnit::CONFIRMED, PollingUnit::NOT_FOUND], true);
        $answers = $settled ? ($check->answers ?? []) : [];

        return new JsonResponse([
            'code' => $row->inec_code,
            'name' => $row->name,
            'listedPlace' => $row->listedPlace(),
            'place' => implode(', ', array_filter([$row->ward?->name, $row->lga?->name])) ?: null,
            'status' => $settled ? $row->status : 'pending',
            'found' => $settled ? (PollingUnitCheck::FOUND[$check->found ?? ''] ?? null) : null,
            'distanceM' => $settled ? $row->listed_distance_m : null,
            'checkedOn' => $settled ? $row->settled_at?->toDateString() : null,
            'answers' => collect(PollingUnitCheck::CHECKLIST)->filter(static fn (array $c, string $key): bool => isset($answers[$key]))
                ->map(static fn (array $c, string $key): array => [
                    'label' => $c[0],
                    'answer' => $c[1][$answers[$key]['answer']] ?? $answers[$key]['answer'],
                    'detail' => $answers[$key]['detail'],
                    'concern' => in_array($answers[$key]['answer'], PollingUnitCheck::CONCERNS[$key] ?? [], true),
                ])->values()->all(),
            'photos' => $settled
                ? $check->clientPhotos()->orderBy('captured_at')->get()->map(static fn (Media $m): array => [
                    'url' => $m->temporaryUrl(10),
                    'label' => $m->kind === Media::KIND_PU_SITE ? 'The unit' : 'The way in',
                ])->values()->all()
                : [],
        ]);
    }

    public function export(Request $request, Campaign $campaign, ExportPollingUnits $export): StreamedResponse
    {
        $this->authorise($campaign);
        $format = $request->query('format');
        abort_unless(is_string($format) && in_array($format, ExportPollingUnits::FORMATS, true), 404);

        VerificationEvent::recordForClient($campaign, 'polling_units.exported', $this->client(), ['format' => $format]);

        return $export($campaign, $format);
    }

    private function authorise(Campaign $campaign): void
    {
        Gate::forUser($this->client())->authorize('view', $campaign);
        abort_unless($campaign->captures(CaptureMode::PollingUnits), 404);
    }

    private function client(): ClientUser
    {
        /** @var ClientUser $client */
        $client = Auth::guard('client')->user();

        return $client;
    }
}
