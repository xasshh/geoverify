<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Claim\Actions\ConfirmClaimCode;
use App\Domain\Claim\Actions\RecordedPhone;
use App\Domain\Claim\Actions\RequestClaimCode;
use App\Domain\Claim\Actions\SearchRegister;
use App\Domain\Claim\Actions\SubmitClaim;
use App\Domain\Claim\Enums\ClaimEvidence;
use App\Domain\Claim\Enums\ClaimRelationship;
use App\Domain\Claim\Models\Claim;
use App\Domain\Claim\Models\ClaimDispute;
use App\Domain\Party\Actions\ActingParty;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Models\Enterprise;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Finding a business in the register and proving it is yours.
 *
 * Access is checked here rather than through a policy. Policies resolve their
 * user from the default guard, and this controller runs on the portal one:
 * routing these checks through Gate would mean either switching the default
 * guard for portal requests, which is how a party ends up evaluated against
 * staff rules, or passing the account in by hand at every call, which is what
 * the explicit checks below already do without the indirection.
 */
final class ClaimController
{
    public function __construct(
        private readonly ActingParty $acting,
    ) {}

    /** The search. Thin results by construction: see SearchRegister. */
    public function search(Request $request, SearchRegister $search): Response
    {
        $membership = $this->membership($request);

        $term = (string) $request->query('q', '');
        $lat = $request->query('lat');
        $lng = $request->query('lng');

        $results = $term === '' && $lat === null
            ? []
            : $search->run(
                $term,
                is_numeric($lat) ? (float) $lat : null,
                is_numeric($lng) ? (float) $lng : null,
            );

        return Inertia::render('portal/ClaimSearch', [
            'term' => $term,
            'results' => $results,
            'searched' => $term !== '' || $lat !== null,
            'party' => [
                'code' => $membership->party?->code,
                'displayName' => $membership->party?->display_name,
            ],
            'relationships' => array_map(static fn (ClaimRelationship $r): array => [
                'value' => $r->value,
                'label' => $r->label(),
            ], ClaimRelationship::cases()),
        ]);
    }

    public function store(Request $request, SubmitClaim $submit): RedirectResponse
    {
        $membership = $this->membership($request);

        if (! $membership->role->claims()) {
            throw new AccessDeniedHttpException('Your access to this party does not include claiming businesses.');
        }

        $data = $request->validate([
            'enterprise_id' => ['required', 'integer', 'exists:enterprises,id'],
            'relationship' => ['required', 'string'],
            // Sent by the browser when the claimant allows it. Never trusted
            // for anything but context: proximity decides nothing.
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $relationship = ClaimRelationship::tryFrom($data['relationship']);

        if (! $relationship instanceof ClaimRelationship) {
            throw ValidationException::withMessages(['relationship' => 'Choose how you are connected to this business.']);
        }

        $enterprise = Enterprise::query()->findOrFail($data['enterprise_id']);

        try {
            $claim = $submit->run(
                $membership->party,
                $this->account(),
                $enterprise,
                $relationship,
                isset($data['lat']) ? (float) $data['lat'] : null,
                isset($data['lng']) ? (float) $data['lng'] : null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['enterprise_id' => $e->getMessage()]);
        }

        return redirect()->route('portal.claim.show', $claim);
    }

    /** Where a claim stands, and what can still be done about it. */
    public function show(Request $request, Claim $claim, RecordedPhone $recorded): Response
    {
        $membership = $this->mustOwnClaim($request, $claim);

        $claim->load(['enterprise.structure.ward', 'enterprise.structure.lga']);

        $dispute = ClaimDispute::query()
            ->where('challenger_claim_id', $claim->id)
            ->first();

        return Inertia::render('portal/ClaimShow', [
            'claim' => [
                'id' => $claim->id,
                'status' => $claim->status->value,
                'statusLabel' => $claim->status->label(),
                'relationship' => $claim->relationship->label(),
                'assertedAt' => $claim->asserted_at->toIso8601String(),
                'decision' => $claim->decision,
                'decisionNote' => $claim->decision_note,
                'phoneConfirmed' => $claim->confirms(ClaimEvidence::PhoneMatch),
            ],
            'business' => [
                'id' => $claim->enterprise->id,
                'tradingName' => $claim->enterprise->trading_name,
                'structureType' => $claim->enterprise->structure->structure_type,
                'ward' => $claim->enterprise->structure->ward?->name,
                'lga' => $claim->enterprise->structure->lga?->name,
            ],
            // Shown as a mask, and the reveal is itself recorded. The claimant
            // has to recognise the number rather than read it.
            // With SMS off a claim is decided by a supervisor, and the hint
            // (whose reveal is recorded) is not shown at all.
            'recordedPhoneHint' => $claim->status->isSettled() || ! SignInController::sms() ? null : $recorded->hintFor($claim),
            'smsEnabled' => SignInController::sms(),
            'dispute' => $dispute === null ? null : [
                'openedAt' => $dispute->opened_at->toIso8601String(),
                'resolution' => $dispute->resolution,
            ],
            'party' => ['code' => $membership->party?->code],
        ]);
    }

    public function sendCode(Request $request, Claim $claim, RequestClaimCode $codes): RedirectResponse
    {
        abort_unless(SignInController::sms(), 404);

        $this->mustOwnClaim($request, $claim);

        try {
            $issued = ($codes)($claim, $request->ip());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        return back()->with('status', "We sent a code to {$issued['masked']}.");
    }

    public function confirmCode(Request $request, Claim $claim, ConfirmClaimCode $confirm): RedirectResponse
    {
        abort_unless(SignInController::sms(), 404);

        $this->mustOwnClaim($request, $claim);

        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);

        try {
            ($confirm)($claim, $data['code']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        return redirect()->route('portal.claim.show', $claim);
    }

    private function account(): PortalAccount
    {
        $account = Auth::guard('portal')->user();

        if (! $account instanceof PortalAccount) {
            throw new AccessDeniedHttpException('Not signed in.');
        }

        return $account;
    }

    private function membership(Request $request): PartyUser
    {
        $membership = $this->acting->forRequest($request, $this->account());

        if (! $membership instanceof PartyUser) {
            throw new AccessDeniedHttpException('Choose which business you are acting for.');
        }

        return $membership;
    }

    /**
     * A claim belongs to a party, not to the person who typed it.
     *
     * Checked against the acting membership rather than against the submitter,
     * so a colleague added to the party afterwards can pick up a claim their
     * predecessor started. Anything else would strand claims when staff change,
     * which for a small business is roughly monthly.
     */
    private function mustOwnClaim(Request $request, Claim $claim): PartyUser
    {
        $membership = $this->membership($request);

        if ($membership->party_id !== $claim->party_id) {
            throw new AccessDeniedHttpException('That claim belongs to another party.');
        }

        return $membership;
    }
}
