<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Claim\Actions\BuildClaimQueue;
use App\Domain\Claim\Actions\DecideClaim;
use App\Domain\Claim\Actions\ResolveDispute;
use App\Domain\Claim\Models\Claim;
use App\Domain\Claim\Models\ClaimDispute;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Claims a person has to decide.
 *
 * Its own route rather than a source folded into the observation queue. The two
 * things being judged are not alike: an observation review asks whether an
 * officer's capture is trustworthy, and a claim review asks whether a stranger
 * is who they say. Mixing them would put photographs and confidence scores next
 * to CAC documents on the same screen and make both harder to read.
 *
 * What is shared is the discipline: a queue ordered by who is worst off, a
 * decision that cannot be recorded without a reason, and an append to
 * verification_events either way.
 */
final class ClaimReviewController
{
    public function index(BuildClaimQueue $queue): Response
    {
        return Inertia::render('console/Claims', $queue());
    }

    public function decide(Request $request, Claim $claim, DecideClaim $decisions): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            // Required on both paths. An approval without a stated reason is
            // exactly as unaccountable as a rejection without one, and it is
            // the approvals that get questioned later.
            'note' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $data['decision'] === 'approve'
                ? $decisions->approveByReview($claim, $this->reviewer(), $data['note'])
                : $decisions->reject($claim, $this->reviewer(), $data['note']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['decision' => $e->getMessage()]);
        }

        return back()->with('status', 'Claim decided.');
    }

    public function resolve(Request $request, ClaimDispute $dispute, ResolveDispute $resolve): RedirectResponse
    {
        $data = $request->validate([
            'resolution' => ['required', 'in:upheld_incumbent,transferred_to_challenger,withdrawn'],
            'note' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            ($resolve)($dispute, $this->reviewer(), $data['resolution'], $data['note']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['resolution' => $e->getMessage()]);
        }

        return back()->with('status', 'Dispute resolved.');
    }

    private function reviewer(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
