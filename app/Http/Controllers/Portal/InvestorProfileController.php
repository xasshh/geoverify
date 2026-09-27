<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Investment\Actions\ManageDataRoom;
use App\Domain\Investment\Actions\ManageOpportunity;
use App\Domain\Investment\Enums\Seeking;
use App\Domain\Investment\Models\DataRoomDocument;
use App\Domain\Investment\Models\DataRoomGrant;
use App\Domain\Investment\Models\InvestorInterest;
use App\Domain\Investment\Models\Opportunity;
use App\Domain\Party\Actions\ActingParty;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Models\Enterprise;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A business decides what investors may read about it.
 *
 * Nothing about a business reaches the investor portal until it publishes an
 * opportunity here, and only the documents it uploads here, to the
 * organisations it lets in, ever leave its data room.
 */
final class InvestorProfileController
{
    public function __construct(
        private readonly ActingParty $acting,
        private readonly ManageOpportunity $manage,
    ) {}

    public function show(Request $request, Enterprise $enterprise): Response
    {
        $this->controlling($request, $enterprise);
        $opportunity = $this->manage->current($enterprise);

        return Inertia::render('portal/Investors', [
            'business' => ['id' => $enterprise->id, 'name' => $enterprise->trading_name],
            'seekingOptions' => Seeking::options(),
            'opportunity' => $opportunity === null ? null : [
                'id' => $opportunity->id,
                'status' => $opportunity->status,
                'seeking' => $opportunity->seeking->value,
                'ticketSizeNaira' => $opportunity->ticket_size_minor === null ? null : intdiv($opportunity->ticket_size_minor, 100),
                'useOfFunds' => $opportunity->use_of_funds,
                'summary' => $opportunity->summary,
                'operatingSince' => $opportunity->operating_since,
                'staffOnSite' => $opportunity->staff_on_site,
                'premises' => $opportunity->premises,
                'publishedAt' => $opportunity->published_at?->toIso8601String(),
                'interested' => InvestorInterest::query()->where('opportunity_id', $opportunity->id)->count(),
            ],
            'documents' => $opportunity === null ? [] : $opportunity->documents()
                ->where('status', DataRoomDocument::STATUS_ACTIVE)
                ->orderBy('created_at')
                ->get()
                ->map(static fn (DataRoomDocument $d): array => [
                    'id' => $d->id,
                    'title' => $d->title,
                    'description' => $d->description,
                    'bytes' => $d->bytes,
                    'addedAt' => $d->created_at?->toIso8601String(),
                ])
                ->all(),
            'requests' => $opportunity === null ? [] : $opportunity->grants()
                ->with('organisation')
                ->orderByRaw("CASE status WHEN 'requested' THEN 0 WHEN 'granted' THEN 1 ELSE 2 END")
                ->orderByDesc('updated_at')
                ->get()
                ->map(static fn (DataRoomGrant $g): array => [
                    'id' => $g->id,
                    'organisation' => $g->organisation?->name,
                    'kind' => $g->organisation?->kind->label(),
                    'verified' => $g->organisation?->isVerified() === true,
                    'status' => $g->status,
                    'message' => $g->message,
                    'requestedAt' => $g->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    public function save(Request $request, Enterprise $enterprise): RedirectResponse
    {
        $membership = $this->controlling($request, $enterprise);

        $input = $request->validate([
            'seeking' => ['required', Rule::enum(Seeking::class)],
            'ticket_size_naira' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'use_of_funds' => ['nullable', 'string', 'max:2000'],
            'summary' => ['nullable', 'string', 'max:4000'],
            'operating_since' => ['nullable', 'integer', 'min:1900', 'max:'.now()->year],
            'staff_on_site' => ['nullable', 'string', 'max:40'],
            'premises' => ['nullable', 'string', 'max:120'],
        ]);

        $opportunity = $this->manage->save($enterprise, $membership->party, $input);

        if ($request->boolean('publish') && ! $opportunity->isPublished()) {
            $this->manage->publish($opportunity, $membership->party);

            return back()->with('status', 'Published. Verified investors can now read this.');
        }

        return back()->with('status', 'Saved.');
    }

    public function withdraw(Request $request, Enterprise $enterprise): RedirectResponse
    {
        $membership = $this->controlling($request, $enterprise);
        $opportunity = $this->manage->current($enterprise);

        abort_if($opportunity === null, 404);

        $this->manage->withdraw($opportunity, $membership->party);

        return back()->with('status', 'Withdrawn. Investors can no longer see this business.');
    }

    public function upload(Request $request, Enterprise $enterprise, ManageDataRoom $rooms): RedirectResponse
    {
        $membership = $this->controlling($request, $enterprise);
        $opportunity = $this->manage->current($enterprise);

        abort_if($opportunity === null, 404);

        $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:240'],
            'document' => ['required', 'file', 'max:20480', 'mimes:pdf,xlsx,xls,csv,docx,doc,jpg,jpeg,png'],
        ]);

        $file = $request->file('document');
        abort_unless($file instanceof UploadedFile, 422);

        $rooms->upload(
            $opportunity,
            $file,
            (string) $request->string('title'),
            $request->filled('description') ? (string) $request->string('description') : null,
            $membership->party,
            $this->account($request),
        );

        return back()->with('status', 'Added to your data room.');
    }

    public function withdrawDocument(Request $request, Enterprise $enterprise, DataRoomDocument $document, ManageDataRoom $rooms): RedirectResponse
    {
        $membership = $this->controlling($request, $enterprise);
        abort_unless($document->opportunity?->enterprise_id === $enterprise->id, 404);

        $rooms->withdrawDocument($document, $membership->party);

        return back()->with('status', 'Removed from your data room.');
    }

    public function decide(Request $request, Enterprise $enterprise, DataRoomGrant $grant, ManageDataRoom $rooms): RedirectResponse
    {
        $membership = $this->controlling($request, $enterprise);
        abort_unless($grant->opportunity?->enterprise_id === $enterprise->id, 404);

        $input = $request->validate([
            'decision' => ['required', Rule::in([DataRoomGrant::STATUS_GRANTED, DataRoomGrant::STATUS_DECLINED, DataRoomGrant::STATUS_REVOKED])],
        ]);

        $rooms->decide($grant, $input['decision'], $membership->party, $this->account($request));

        return back()->with('status', match ($input['decision']) {
            DataRoomGrant::STATUS_GRANTED => 'Access granted.',
            DataRoomGrant::STATUS_DECLINED => 'Request declined.',
            default => 'Access revoked.',
        });
    }

    private function account(Request $request): PortalAccount
    {
        $account = $request->user('portal');

        if (! $account instanceof PortalAccount) {
            throw new AccessDeniedHttpException('Sign in first.');
        }

        return $account;
    }

    private function controlling(Request $request, Enterprise $enterprise): PartyUser
    {
        $membership = $this->acting->forRequest($request, $this->account($request));

        if (! $membership instanceof PartyUser) {
            throw new AccessDeniedHttpException('You do not manage this business.');
        }

        $controls = PartyBusiness::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('party_id', $membership->party_id)
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->exists();

        if (! $controls) {
            throw new AccessDeniedHttpException('You do not manage this business.');
        }

        return $membership;
    }
}
