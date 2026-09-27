<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invest;

use App\Domain\Investment\Actions\KeepInvestorRecords;
use App\Domain\Investment\Actions\ManageDataRoom;
use App\Domain\Investment\Actions\ReadDossier;
use App\Domain\Investment\Actions\ReadInvestorMap;
use App\Domain\Investment\Actions\ReadInvestorOverview;
use App\Domain\Investment\Actions\ReadOpportunities;
use App\Domain\Investment\Enums\Seeking;
use App\Domain\Investment\Models\DataRoomDocument;
use App\Domain\Investment\Models\DataRoomGrant;
use App\Domain\Investment\Models\InvestorUser;
use App\Domain\Investment\Models\Opportunity;
use App\Domain\Investment\Models\WatchlistEntry;
use App\Domain\Verification\Exports\LoopbackPrint;
use App\Domain\Verification\Exports\PdfRenderer;
use App\Domain\Verification\Models\VerificationEvent;
use App\Domain\Verification\Models\VerificationOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The investor portal's screens.
 *
 * Every read goes through ReadOpportunities or ReadDossier, so no screen here
 * can show an opportunity another would not. Every write is an action in
 * App\Domain\Investment, and every one is scoped to the signed-in person's
 * organisation: nothing here takes an organisation id from the request.
 */
final class InvestorController
{
    public function __construct(
        private readonly ReadOpportunities $opportunities,
        private readonly ReadInvestorOverview $overview,
    ) {}

    public function overview(Request $request): Response
    {
        $investor = $this->investor($request);
        $state = $this->filter($request, 'region');
        $sector = $this->filter($request, 'sector');

        return Inertia::render('invest/Overview', [
            'filters' => ['region' => $state, 'sector' => $sector],
            'regions' => $this->overview->stateOptions(),
            'sectorOptions' => $this->overview->sectorOptions(),
            'overview' => ($this->overview)($investor->investor_organisation_id, $state, $sector),
            // Opportunities name businesses, so an organisation still in KYC
            // is sent none: hiding them in the page would still ship them.
            'featured' => $investor->isVerified()
                ? array_slice($this->opportunities->rows(
                    $investor->investor_organisation_id,
                    ['state' => $state, 'sector' => $sector],
                ), 0, 6)
                : [],
        ]);
    }

    public function explore(Request $request, ReadInvestorMap $map): Response
    {
        $investor = $this->investor($request);
        $state = $this->filter($request, 'region');

        return Inertia::render('invest/Explore', [
            'map' => $map($investor->investor_organisation_id, $investor->isVerified()),
            'selected' => $state,
            'overview' => ($this->overview)($investor->investor_organisation_id),
            'opportunities' => $investor->isVerified()
                ? $this->opportunities->rows($investor->investor_organisation_id, ['state' => $state])
                : [],
        ]);
    }

    public function opportunities(Request $request): Response
    {
        $investor = $this->investor($request);
        $filters = [
            'state' => $this->filter($request, 'region'),
            'sector' => $this->filter($request, 'sector'),
            'seeking' => $this->filter($request, 'seeking'),
        ];

        return Inertia::render('invest/Opportunities', [
            'filters' => ['region' => $filters['state'], 'sector' => $filters['sector'], 'seeking' => $filters['seeking']],
            'regions' => $this->overview->stateOptions(),
            'sectorOptions' => $this->overview->sectorOptions(),
            'seekingOptions' => Seeking::options(),
            'opportunities' => $this->opportunities->rows($investor->investor_organisation_id, $filters),
        ]);
    }

    public function show(Request $request, Opportunity $opportunity, ReadDossier $dossier): Response
    {
        $investor = $this->investor($request);
        $read = $dossier($opportunity->id, $investor->investor_organisation_id);

        abort_if($read === null, 404);

        VerificationEvent::recordForInvestor($opportunity, 'opportunity.viewed', $investor);

        return Inertia::render('invest/Dossier', ['dossier' => $read]);
    }

    public function watchlist(Request $request): Response
    {
        $investor = $this->investor($request);

        $ids = WatchlistEntry::query()
            ->where('investor_organisation_id', $investor->investor_organisation_id)
            ->whereNull('removed_at')
            ->pluck('opportunity_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return Inertia::render('invest/Watchlist', [
            'opportunities' => $this->opportunities->rows($investor->investor_organisation_id, ['ids' => $ids]),
        ]);
    }

    public function dataRooms(Request $request): Response
    {
        $investor = $this->investor($request);

        $grants = DataRoomGrant::query()
            ->where('investor_organisation_id', $investor->investor_organisation_id)
            ->orderByDesc('updated_at')
            ->get();

        $rows = $this->opportunities->rows($investor->investor_organisation_id, [
            'ids' => $grants->pluck('opportunity_id')->map(static fn ($id): int => (int) $id)->all(),
        ]);
        $byId = array_column($rows, null, 'id');

        return Inertia::render('invest/DataRooms', [
            'rooms' => $grants
                ->filter(static fn (DataRoomGrant $g): bool => isset($byId[$g->opportunity_id]))
                ->map(fn (DataRoomGrant $g): array => [
                    'opportunity' => $byId[$g->opportunity_id],
                    'status' => $g->status,
                    'requestedAt' => $g->created_at?->toIso8601String(),
                    'decidedAt' => $g->decided_at?->toIso8601String(),
                    'documents' => $g->isGranted()
                        ? DataRoomDocument::query()
                            ->where('opportunity_id', $g->opportunity_id)
                            ->where('status', DataRoomDocument::STATUS_ACTIVE)
                            ->count()
                        : 0,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function reports(Request $request): Response
    {
        $investor = $this->investor($request);

        // The certificates of every opportunity this organisation can see that
        // has one. Commissioned reports join them with I2.
        $rows = $this->opportunities->rows($investor->investor_organisation_id);
        $enterpriseIds = array_column($rows, 'enterpriseId');
        $byEnterprise = array_column($rows, null, 'enterpriseId');

        $certificates = $enterpriseIds === [] ? collect() : VerificationOrder::query()
            ->whereIn('enterprise_id', $enterpriseIds)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->get();

        return Inertia::render('invest/Reports', [
            'commissioned' => VerificationOrder::query()
                ->where('investor_organisation_id', $investor->investor_organisation_id)
                ->with('enterprise:id,trading_name')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(static fn (VerificationOrder $o): array => [
                    'id' => $o->id,
                    'reference' => $o->reference,
                    'business' => $o->enterprise?->trading_name,
                    'tier' => CommissionController::TIERS[$o->tier]['name'] ?? $o->tier,
                    'statusLabel' => $o->status->label(),
                    'status' => $o->status->value,
                ])
                ->all(),
            'reports' => $certificates->map(static fn (VerificationOrder $order): array => [
                'orderId' => $order->id,
                'reference' => $order->reference,
                'tier' => str_replace('_', ' ', $order->tier),
                'issuedOn' => $order->completed_at?->toDateString(),
                'opportunity' => $byEnterprise[$order->enterprise_id],
            ])->values()->all(),
        ]);
    }

    public function settings(Request $request): Response
    {
        $investor = $this->investor($request);
        $organisation = $investor->organisation;

        return Inertia::render('invest/Settings', [
            'profile' => [
                'name' => $investor->name,
                'title' => $investor->title,
                'email' => $investor->email,
            ],
            'organisation' => [
                'name' => $organisation?->name,
                'kind' => $organisation?->kind->label(),
                'kycStatus' => $organisation?->kyc_status,
                'kycDecidedAt' => $organisation?->kyc_decided_at?->toDateString(),
            ],
            'team' => $organisation === null ? [] : $organisation->users()
                ->orderBy('name')
                ->get(['id', 'name', 'title', 'email', 'status'])
                ->map(static fn (InvestorUser $u): array => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'title' => $u->title,
                    'email' => $u->email,
                    'status' => $u->status,
                ])
                ->all(),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $investor = $this->investor($request);

        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:80'],
        ]);

        $investor->forceFill($input)->save();

        return back()->with('status', 'Saved.');
    }

    public function watch(Request $request, Opportunity $opportunity, KeepInvestorRecords $records): RedirectResponse
    {
        $investor = $this->investor($request);
        $this->visible($opportunity, $investor);

        $watching = $records->toggleWatch($opportunity, $investor);

        return back()->with('status', $watching ? 'Added to your watchlist.' : 'Removed from your watchlist.');
    }

    public function interest(Request $request, Opportunity $opportunity, KeepInvestorRecords $records): RedirectResponse
    {
        $investor = $this->investor($request);
        $this->visible($opportunity, $investor);

        $input = $request->validate(['message' => ['nullable', 'string', 'max:2000']]);
        $records->expressInterest($opportunity, $investor, $input['message'] ?? null);

        return back()->with('status', 'The business has been told your organisation is interested.');
    }

    public function note(Request $request, Opportunity $opportunity, KeepInvestorRecords $records): RedirectResponse
    {
        $investor = $this->investor($request);
        $this->visible($opportunity, $investor);

        $input = $request->validate(['body' => ['present', 'nullable', 'string', 'max:10000']]);
        $records->saveNote($opportunity, $investor, (string) ($input['body'] ?? ''));

        return back();
    }

    public function requestRoom(Request $request, Opportunity $opportunity, ManageDataRoom $rooms): RedirectResponse
    {
        $investor = $this->investor($request);
        $this->visible($opportunity, $investor);

        $input = $request->validate(['message' => ['nullable', 'string', 'max:2000']]);
        $grant = $rooms->request($opportunity, $investor, $input['message'] ?? null);

        return back()->with('status', $grant->isGranted()
            ? 'The data room is already open to you.'
            : 'Access requested. The business decides, and you will see the room here once it agrees.');
    }

    public function document(Request $request, Opportunity $opportunity, DataRoomDocument $document): StreamedResponse
    {
        $investor = $this->investor($request);
        $this->visible($opportunity, $investor);

        abort_unless(
            $document->opportunity_id === $opportunity->id
            && $document->status === DataRoomDocument::STATUS_ACTIVE,
            404,
        );

        $granted = DataRoomGrant::query()
            ->where('opportunity_id', $opportunity->id)
            ->where('investor_organisation_id', $investor->investor_organisation_id)
            ->where('status', DataRoomGrant::STATUS_GRANTED)
            ->exists();

        abort_unless($granted, 403, 'The business has not opened its data room to your organisation.');

        VerificationEvent::recordForInvestor($opportunity, 'data_room.document_read', $investor, ['document_id' => $document->id]);

        return Storage::disk($document->disk)->download(
            $document->path,
            str($document->title)->slug()->append('.', pathinfo($document->path, PATHINFO_EXTENSION))->toString(),
        );
    }

    public function certificate(Request $request, Opportunity $opportunity, VerificationOrder $order, PdfRenderer $renderer): StreamedResponse
    {
        $investor = $this->investor($request);
        $this->visible($opportunity, $investor);

        abort_unless($order->enterprise_id === $opportunity->enterprise_id && $order->status->value === 'completed', 404);
        abort_unless($renderer->available(), 503, 'No headless browser is installed on this server, so a certificate cannot be printed.');

        $url = URL::temporarySignedRoute('portal.certificate.render', now()->addMinutes(2), ['order' => $order->id], absolute: false);
        $file = tempnam(sys_get_temp_dir(), 'geoverify-certificate-').'.pdf';
        $renderer->render(app(LoopbackPrint::class)->url($request, $url), $file);

        VerificationEvent::recordForInvestor($order, 'certificate.downloaded', $investor);

        return response()->streamDownload(
            function () use ($file): void {
                try {
                    echo (string) file_get_contents($file);
                } finally {
                    @unlink($file);
                }
            },
            'geoverify-'.strtolower($order->reference).'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    private function investor(Request $request): InvestorUser
    {
        $investor = $request->user('investor');
        abort_unless($investor instanceof InvestorUser, 403);

        return $investor;
    }

    /** The opportunity must be one ReadOpportunities would show, or it does not exist. */
    private function visible(Opportunity $opportunity, InvestorUser $investor): void
    {
        abort_if($this->opportunities->one($opportunity->id, $investor->investor_organisation_id) === null, 404);
    }

    private function filter(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
