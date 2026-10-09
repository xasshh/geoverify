<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domain\AreaCapture\Actions\AssembleAreaSummary;
use App\Domain\AreaCapture\Actions\ExportAreaFeatures;
use App\Domain\Campaign\Enums\CaptureMode;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\ClientUser;
use App\Domain\Imagery\Models\BasemapLayer;
use App\Domain\Verification\Exports\LoopbackPrint;
use App\Domain\Verification\Models\VerificationEvent;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The land a campaign has mapped, as its client sees it.
 *
 * A map by class, a summary in hectares and kilometres, verification against
 * the sample target, the exports and the area report. Who captured a feature
 * never reaches this surface: the client bought the land, not the officers.
 */
final class LandController extends Controller
{
    public function show(Campaign $campaign, AssembleAreaSummary $summary): Response
    {
        $this->authorise($campaign);

        $areas = DB::select(<<<'SQL'
            SELECT ca.id, ca.name,
                   ST_XMin(ca.boundary) AS w, ST_YMin(ca.boundary) AS s, ST_XMax(ca.boundary) AS e, ST_YMax(ca.boundary) AS n,
                   (SELECT count(*) FROM area_features f WHERE f.coverage_area_id = ca.id AND f.status = 'live') AS features
              FROM coverage_areas ca
             WHERE ca.campaign_id = ?
             ORDER BY ca.name
        SQL, [$campaign->id]);

        return Inertia::render('client/CampaignLand', [
            'campaign' => [
                'id' => $campaign->id,
                'code' => $campaign->code,
                'name' => $campaign->name,
            ],
            'summary' => $summary($campaign),
            'areas' => array_map(fn (object $area): array => [
                'id' => (int) $area->id,
                'name' => (string) $area->name,
                'bounds' => [(float) $area->w, (float) $area->s, (float) $area->e, (float) $area->n],
                'features' => (int) $area->features,
                'imagery' => $this->imageryFor((int) $area->id),
            ], $areas),
            'formats' => [
                ['key' => 'geojson', 'label' => 'GeoJSON'],
                ['key' => 'gpkg', 'label' => 'GeoPackage'],
                ['key' => 'shp', 'label' => 'Shapefile'],
                ['key' => 'kml', 'label' => 'KML'],
                ['key' => 'csv', 'label' => 'CSV with WKT'],
            ],
        ]);
    }

    /**
     * One mandate's features for the map, lightly simplified for the screen
     * (to about ten metres, a 10 m land cover pixel). The exports carry the shapes at full precision.
     */
    public function features(Campaign $campaign, int $area): JsonResponse
    {
        $this->authorise($campaign);

        $rows = DB::select(<<<'SQL'
            SELECT f.client_uuid, fc.id AS class_id, fc.label, fc.style, f.verification_status, r.capture_method,
                   r.area_ha, r.length_m, r.captured_at,
                   ST_AsGeoJSON(CASE WHEN GeometryType(r.geom) = 'POINT' THEN r.geom
                                     ELSE ST_SimplifyPreserveTopology(r.geom, 0.0001) END, 5) AS geometry
              FROM area_features f
              JOIN area_feature_revisions r ON r.id = f.current_revision_id
              JOIN feature_classes fc ON fc.id = f.feature_class_id
             WHERE f.campaign_id = ? AND f.coverage_area_id = ? AND f.status = 'live'
        SQL, [$campaign->id, $area]);

        return new JsonResponse([
            'type' => 'FeatureCollection',
            'features' => array_map(static fn (object $row): array => [
                'type' => 'Feature',
                'geometry' => json_decode((string) $row->geometry, true),
                'properties' => [
                    'uuid' => $row->client_uuid,
                    'classId' => (int) $row->class_id,
                    'label' => $row->label,
                    'colour' => AssembleAreaSummary::colour($row->style),
                    'verification' => $row->verification_status,
                    'method' => in_array($row->capture_method, ExportAreaFeatures::METHOD_GROUPS['field'], true) ? 'field' : 'desk',
                    'areaHa' => $row->area_ha === null ? null : (float) $row->area_ha,
                    'lengthM' => $row->length_m === null ? null : (float) $row->length_m,
                    'captured' => substr((string) $row->captured_at, 0, 10),
                ],
            ], $rows),
        ], headers: ['Cache-Control' => 'private, max-age=60']);
    }

    /** The imagery under one mandate, read by range by the map. */
    public function imagery(Campaign $campaign, int $area): BinaryFileResponse|SymfonyRedirect
    {
        $this->authorise($campaign);
        $layer = BasemapLayer::query()
            ->where('coverage_area_id', $area)
            ->whereIn('coverage_area_id', $campaign->coverageAreas()->select('id'))
            ->current()
            ->latest('built_at')
            ->firstOrFail();

        $disk = Storage::disk('media');

        if (config('filesystems.disks.media.driver') !== 'local') {
            return redirect()->away($disk->temporaryUrl((string) $layer->path, now()->addHour()));
        }

        return response()->file($disk->path((string) $layer->path), [
            'Content-Type' => 'application/vnd.pmtiles',
            'ETag' => '"'.$layer->checksum.'"',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function export(Request $request, Campaign $campaign, ExportAreaFeatures $export): BinaryFileResponse
    {
        $this->authorise($campaign);

        $data = $request->validate([
            'format' => ['required', Rule::in(ExportAreaFeatures::FORMATS)],
            'classes' => ['array'],
            'classes.*' => ['integer', Rule::exists('feature_classes', 'id')->where('campaign_id', $campaign->id)],
            'method' => ['nullable', Rule::in(array_keys(ExportAreaFeatures::METHOD_GROUPS))],
            'verification' => ['array'],
            'verification.*' => [Rule::in(['unverified', 'verified', 'rejected', 'needs_revisit'])],
            'area' => ['nullable', 'integer', Rule::exists('coverage_areas', 'id')->where('campaign_id', $campaign->id)],
        ]);

        $file = $export($campaign, (string) $data['format'], [
            'classes' => array_values(array_map('intval', $data['classes'] ?? [])),
            'method' => $data['method'] ?? null,
            'verification' => array_values(array_map('strval', $data['verification'] ?? [])),
            'area' => isset($data['area']) ? (int) $data['area'] : null,
        ], $this->client());

        return response()->download($file['path'], $file['filename'], ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /** The area report, printed by the same engine as every other document. */
    public function report(Request $request, Campaign $campaign, LoopbackPrint $print): StreamedResponse
    {
        $this->authorise($campaign);

        if (! $print->available()) {
            abort(503, 'No headless browser is installed on this server, so a report cannot be printed.');
        }

        $signed = URL::temporarySignedRoute(
            'client.campaigns.land.report.render',
            now()->addMinutes(2),
            ['campaign' => $campaign->id],
            absolute: false,
        );

        $pdf = $print->toString($print->url($request, $signed));

        VerificationEvent::recordForClient($campaign, 'area_report.printed', $this->client(), [
            'sha256' => hash('sha256', $pdf),
            'bytes' => strlen($pdf),
        ]);

        return response()->streamDownload(
            static function () use ($pdf): void {
                echo $pdf;
            },
            str($campaign->code)->lower()->slug().'-land-report.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * The printable HTML. Signed, short lived and refused off the loopback
     * interface, like the brief: the browser printing it has no session.
     */
    public function reportHtml(Request $request, Campaign $campaign, AssembleAreaSummary $summary): View
    {
        if (! $request->hasValidSignature(absolute: false)) {
            abort(403, 'That link has expired.');
        }

        if (! in_array($request->ip(), ['127.0.0.1', '::1', null], true)) {
            abort(403, 'The report renders only on the host that asked for it.');
        }

        abort_unless($campaign->captures(CaptureMode::AreaFeatures), 404);

        return view('exports.area-report', [
            'campaign' => $campaign,
            'summary' => $summary($campaign),
            'map' => $this->sketch($campaign),
            'areas' => DB::table('coverage_areas')->where('campaign_id', $campaign->id)->orderBy('name')->pluck('name')->all(),
            'sources' => DB::table('area_feature_batches as b')
                ->join('coverage_areas as ca', 'ca.id', '=', 'b.coverage_area_id')
                ->where('ca.campaign_id', $campaign->id)
                ->where('b.status', 'done')
                ->distinct()
                ->pluck('b.source_name')
                ->filter()
                ->values()
                ->all(),
        ]);
    }

    /**
     * The campaign's land drawn as SVG by PostGIS: one path per class, in web
     * mercator, simplified to what an A4 page can show. Points are left to the
     * table; at this scale they are noise. Each class is extracted to a single
     * multi geometry first: a mixed collection (polygons beside multipolygons,
     * which a land cover seed always produces) comes out of ST_AsSVG as
     * semicolon separated pieces that no path can draw.
     *
     * @return array{viewBox: string, stroke: float, paths: list<array{d: string, colour: string, line: bool}>, boundary: string}|null
     */
    private function sketch(Campaign $campaign): ?array
    {
        $extent = DB::selectOne(<<<'SQL'
            SELECT ST_XMin(e) AS x0, ST_YMin(e) AS y0, ST_XMax(e) AS x1, ST_YMax(e) AS y1
              FROM (SELECT ST_Extent(ST_Transform(boundary, 3857))::geometry AS e FROM coverage_areas WHERE campaign_id = ?) x
        SQL, [$campaign->id]);

        if ($extent === null || $extent->x0 === null) {
            return null;
        }

        $width = max(1.0, (float) $extent->x1 - (float) $extent->x0);
        $height = max(1.0, (float) $extent->y1 - (float) $extent->y0);
        $tolerance = max($width, $height) / 1200;

        $paths = DB::select(<<<'SQL'
            SELECT fc.style, fc.geometry_type,
                   ST_AsSVG(ST_Multi(ST_CollectionExtract(ST_SimplifyPreserveTopology(ST_Collect(ST_Transform(r.geom, 3857)), ?),
                                                       CASE WHEN fc.geometry_type = 'line' THEN 2 ELSE 3 END)), 0, 0) AS d
              FROM area_features f
              JOIN area_feature_revisions r ON r.id = f.current_revision_id
              JOIN feature_classes fc ON fc.id = f.feature_class_id
             WHERE f.campaign_id = ? AND f.status = 'live' AND fc.geometry_type <> 'point'
             GROUP BY fc.id
             ORDER BY (fc.geometry_type = 'line'), fc.sort_order, fc.id
        SQL, [$tolerance, $campaign->id]);

        $boundary = (string) DB::scalar(
            'SELECT ST_AsSVG(ST_Multi(ST_CollectionExtract(ST_SimplifyPreserveTopology(ST_Collect(ST_Transform(boundary, 3857)), ?), 3)), 0, 0) FROM coverage_areas WHERE campaign_id = ?',
            [$tolerance, $campaign->id],
        );

        // ST_AsSVG flips y, so the box starts at minus the top edge.
        return [
            'viewBox' => sprintf('%.0f %.0f %.0f %.0f', $extent->x0, -$extent->y1, $width, $height),
            'stroke' => $tolerance * 1.2,
            'paths' => array_map(static fn (object $row): array => [
                'd' => (string) $row->d,
                'colour' => AssembleAreaSummary::colour($row->style),
                'line' => $row->geometry_type === 'line',
            ], $paths),
            'boundary' => $boundary,
        ];
    }

    /** @return array{url: string, captured: string|null}|null */
    private function imageryFor(int $areaId): ?array
    {
        $layer = BasemapLayer::query()->where('coverage_area_id', $areaId)->current()->latest('built_at')->first();

        return $layer === null ? null : [
            'url' => '/client/campaigns/'.DB::table('coverage_areas')->where('id', $areaId)->value('campaign_id').'/land/'.$areaId.'/imagery.pmtiles',
            'captured' => $layer->capturedLabel(),
        ];
    }

    private function authorise(Campaign $campaign): void
    {
        Gate::forUser($this->client())->authorize('view', $campaign);
        abort_unless($campaign->captures(CaptureMode::AreaFeatures), 404);
    }

    private function client(): ClientUser
    {
        /** @var ClientUser $client */
        $client = Auth::guard('client')->user();

        return $client;
    }
}
