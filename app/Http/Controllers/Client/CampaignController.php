<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domain\Campaign\Actions\AcknowledgeCampaign;
use App\Domain\Campaign\Actions\AssembleCampaignDossier;
use App\Domain\Campaign\Enums\CampaignStatus;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\ClientUser;
use App\Domain\Coverage\Actions\ReadRoadNetwork;
use App\Domain\Verification\Exports\LoopbackPrint;
use Illuminate\Contracts\View\View;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What a client sees of the work they commissioned.
 *
 * Scoped twice over, and deliberately. Every list is built through
 * Campaign::visibleToClient(), so another organisation's rows are never in the
 * result set; every single record is then authorised through the policy, so a
 * direct hit on somebody else's id is a 403 rather than a blank page that
 * leaves you wondering whether the record exists.
 *
 * Nothing here reaches campaign_commercials. AssembleCampaignDossier has no code
 * path to it at all, which is what makes that a property of the design rather
 * than of somebody remembering.
 */
final class CampaignController
{
    /**
     * The landing view: whichever campaign is being worked right now.
     *
     * Where a client holds several, the one they last looked at is remembered
     * in the session. A client with two exercises running should not have to
     * re-choose on every visit, and a switcher that forgets is worse than none.
     */
    public function dashboard(Request $request, AssembleCampaignDossier $dossier, AcknowledgeCampaign $acknowledge): Response
    {
        $client = $this->client();

        $live = Campaign::query()
            ->visibleToClient($client->client_organisation_id)
            ->where('status', CampaignStatus::Active->value)
            ->orderBy('starts_on')
            ->get();

        $selected = $this->remembered($request, $live);

        return Inertia::render('client/Dashboard', [
            'organisation' => [
                'name' => $client->organisation?->name,
                'shortCode' => $client->organisation?->short_code,
            ],
            'active' => $live->map(static fn (Campaign $campaign): array => [
                'id' => $campaign->id,
                'code' => $campaign->code,
                'name' => $campaign->name,
            ])->values()->all(),
            'campaign' => $selected === null ? null : $dossier($selected),
            'mustAcknowledge' => $selected !== null && $acknowledge->outstandingFor($selected, $client),
        ]);
    }

    /** Everything they have ever commissioned, live or finished. */
    public function index(): Response
    {
        $client = $this->client();

        $campaigns = Campaign::query()
            ->visibleToClient($client->client_organisation_id)
            ->withCount('coverageAreas')
            ->orderByDesc('starts_on')
            ->get();

        return Inertia::render('client/Campaigns', [
            'campaigns' => $campaigns->map(static fn (Campaign $campaign): array => [
                'id' => $campaign->id,
                'code' => $campaign->code,
                'name' => $campaign->name,
                'subjectType' => $campaign->subject_type,
                'status' => $campaign->status->value,
                'statusLabel' => $campaign->status->label(),
                'startsOn' => $campaign->starts_on?->toDateString(),
                'endsOn' => $campaign->ends_on?->toDateString(),
                'targetRecordCount' => $campaign->target_record_count,
                'areaCount' => $campaign->coverage_areas_count,
            ])->values()->all(),
        ]);
    }

    /** The dossier: the whole picture of one exercise. */
    public function show(Request $request, Campaign $campaign, AssembleCampaignDossier $dossier, AcknowledgeCampaign $acknowledge): Response
    {
        Gate::forUser($this->client())->authorize('view', $campaign);

        $request->session()->put('client.campaign', $campaign->id);

        return Inertia::render('client/CampaignDossier', [
            'campaign' => $dossier($campaign),
            'mustAcknowledge' => $acknowledge->outstandingFor($campaign, $this->client()),
            'briefUrl' => route('client.campaigns.brief', $campaign),
            'roadsUrl' => route('client.campaigns.roads', $campaign),
        ]);
    }

    /**
     * The street network under this campaign's ground.
     *
     * Its own request rather than part of the dossier payload. The outlines and
     * the numbers are what the page is about and they should paint immediately;
     * the roads are a quarter of a megabyte of context that can arrive a moment
     * later without anybody noticing.
     */
    public function roads(Request $request, Campaign $campaign, ReadRoadNetwork $network): JsonResponse
    {
        Gate::forUser($this->client())->authorize('view', $campaign);

        $boundary = 'select ST_Union(boundary) from coverage_areas where campaign_id = ?';
        $level = $request->string('detail')->toString() === ReadRoadNetwork::DETAIL
            ? ReadRoadNetwork::DETAIL
            : ReadRoadNetwork::OVERVIEW;

        return new JsonResponse([
            'roads' => $network->forBoundary($boundary, [$campaign->id], $level),
            'labels' => $network->labelsFor($boundary, [$campaign->id]),
        ]);
    }

    public function acknowledge(Campaign $campaign, AcknowledgeCampaign $acknowledge): RedirectResponse
    {
        Gate::forUser($this->client())->authorize('view', $campaign);

        $acknowledge->record($campaign, $this->client());

        return back();
    }

    /**
     * The one page brief, printed by the same engine as the evidence pack.
     *
     * A second signed loopback route, not a second PDF pipeline. The browser
     * doing the printing has no session, so it fetches a short lived signature
     * over a relative path, and the layout inherits the self hosted fonts the
     * rest of the system already serves.
     */
    public function brief(Request $request, Campaign $campaign, LoopbackPrint $print): StreamedResponse
    {
        Gate::forUser($this->client())->authorize('view', $campaign);

        if (! $print->available()) {
            abort(503, 'No headless browser is installed on this server, so a brief cannot be printed.');
        }

        $signed = URL::temporarySignedRoute(
            'client.campaigns.brief.render',
            now()->addMinutes(2),
            ['campaign' => $campaign->id],
            absolute: false,
        );

        $pdf = $print->toString($print->url($request, $signed));
        $name = str($campaign->code)->lower()->slug().'-brief.pdf';

        return response()->streamDownload(
            static function () use ($pdf): void {
                echo $pdf;
            },
            $name,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * The printable HTML the renderer fetches.
     *
     * Signed and short lived rather than session authenticated, because the
     * headless browser is not the person, and refused off the loopback
     * interface so a leaked signature is still useless to anybody elsewhere.
     * Authorisation happened on the request that minted the signature.
     */
    public function briefHtml(Request $request, Campaign $campaign, AssembleCampaignDossier $dossier): ViewContract
    {
        if (! $request->hasValidSignature(absolute: false)) {
            abort(403, 'That link has expired.');
        }

        if (! in_array($request->ip(), ['127.0.0.1', '::1', null], true)) {
            abort(403, 'The brief renders only on the host that asked for it.');
        }

        return view('exports.campaign-brief', [
            'campaign' => $dossier($campaign),
        ]);
    }

    /**
     * Which campaign the dashboard should open on.
     *
     * @param  Collection<int, Campaign>  $live
     */
    private function remembered(Request $request, $live): ?Campaign
    {
        if ($live->isEmpty()) {
            return null;
        }

        $rememberedId = $request->session()->get('client.campaign');

        $chosen = $live->firstWhere('id', $rememberedId) ?? $live->first();

        $request->session()->put('client.campaign', $chosen->id);

        return $chosen;
    }

    private function client(): ClientUser
    {
        /** @var ClientUser $client */
        $client = Auth::guard('client')->user();

        return $client;
    }
}
