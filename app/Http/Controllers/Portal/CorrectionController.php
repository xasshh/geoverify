<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Media\Actions\StorePartyDocument;
use App\Domain\Party\Actions\ActingParty;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Actions\ProposeCorrection;
use App\Domain\Registry\Actions\SetPublicationState;
use App\Domain\Registry\Enums\CorrectableField;
use App\Domain\Registry\Enums\CorrectionStatus;
use App\Domain\Registry\Enums\PublicationState;
use App\Domain\Registry\Models\CorrectionProposal;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * What a party can do about a listing being wrong.
 *
 * Propose, withdraw, and decide whether to be published. Notably not: edit.
 * The listing page has no edit form and this controller has no update method,
 * which is the same design decision expressed twice.
 */
final class CorrectionController
{
    public function __construct(private readonly ActingParty $acting) {}

    public function store(
        Request $request,
        Enterprise $enterprise,
        ProposeCorrection $propose,
        StorePartyDocument $documents,
    ): RedirectResponse {
        $membership = $this->membership($request);

        $field = CorrectableField::tryFrom((string) $request->input('field'));

        if ($field === null) {
            throw ValidationException::withMessages([
                'field' => 'That is not something the register can correct.',
            ]);
        }

        // The field's own rules, so a phone number that is not a phone number is
        // refused at the point of typing rather than three days later by a
        // supervisor whose morning it wasted.
        $data = $request->validate([
            'proposed_value' => $field->rules(),
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
            'evidence' => ['nullable', 'file', 'max:8192'],
        ]);

        $evidence = null;

        try {
            if ($request->hasFile('evidence')) {
                $evidence = $documents(
                    $request->file('evidence'),
                    $enterprise,
                    $membership->party,
                    $this->account($request),
                    (string) Str::uuid7(),
                );
            }

            $propose(
                $membership->party,
                $this->account($request),
                $enterprise,
                $field,
                $data['proposed_value'] === null ? null : (string) $data['proposed_value'],
                $data['reason'],
                $evidence,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['proposed_value' => $e->getMessage()]);
        }

        return back()->with('status', 'Sent for review. We will tell you what was decided.');
    }

    /** Taking it back before anybody has ruled. */
    public function withdraw(Request $request, CorrectionProposal $proposal): RedirectResponse
    {
        $membership = $this->membership($request);

        if ($proposal->party_id !== $membership->party_id) {
            throw new AccessDeniedHttpException('That is not your correction.');
        }

        if ($proposal->status !== CorrectionStatus::Submitted) {
            throw ValidationException::withMessages([
                'status' => 'That correction has already been decided.',
            ]);
        }

        $proposal->update(['status' => CorrectionStatus::Withdrawn]);

        VerificationEvent::record(
            $proposal->enterprise,
            'correction.withdrawn',
            null,
            ['proposal_id' => $proposal->id, 'field' => $proposal->field->value],
            VerificationEvent::ACTOR_PARTY,
        );

        return back()->with('status', 'Withdrawn.');
    }

    public function publication(
        Request $request,
        Enterprise $enterprise,
        SetPublicationState $publication,
    ): RedirectResponse {
        $membership = $this->membership($request);

        $data = $request->validate([
            'state' => ['required', Rule::enum(PublicationState::class)],
        ]);

        try {
            $publication(
                $membership->party,
                $this->account($request),
                $enterprise,
                PublicationState::from($data['state']),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['state' => $e->getMessage()]);
        }

        return back()->with('status', 'Saved.');
    }

    private function account(Request $request): PortalAccount
    {
        $account = Auth::guard('portal')->user();

        if (! $account instanceof PortalAccount) {
            throw new AccessDeniedHttpException('Not signed in.');
        }

        return $account;
    }

    private function membership(Request $request): PartyUser
    {
        $membership = $this->acting->forRequest($request, $this->account($request));

        if (! $membership instanceof PartyUser) {
            throw new AccessDeniedHttpException('Choose which business you are acting for.');
        }

        return $membership;
    }
}
