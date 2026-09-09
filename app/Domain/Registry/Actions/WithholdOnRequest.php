<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Registry\Enums\PublicationState;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Somebody asks for a listing to come down, and it comes down.
 *
 * No claim, no account, no proof. That asymmetry is deliberate and it is the
 * whole point: a business that never asked to be in a public directory should
 * not have to prove it owns itself to get out of one. Publishing requires
 * proving control; being left alone does not.
 *
 * What it costs is that a competitor can hide a rival's listing. What it buys
 * is that a business with a reason to be invisible, and there are such
 * businesses, does not have to argue with a form to become invisible. The
 * damage is bounded and reversible in one direction only: the record itself is
 * untouched, nothing is deleted, and the listing returns the moment its actual
 * owner claims it and opts in, which takes proof. A wrongly hidden business is
 * a nuisance for its owner. A wrongly shown one can be a danger to somebody.
 *
 * A record already opted in by a proven owner is left alone: that owner made a
 * decision with a consent receipt behind it, and a stranger does not get to
 * overrule it from a public page. They can still ask, and the request is
 * recorded for a supervisor, which is the honest place for that conflict.
 */
final class WithholdOnRequest
{
    public function __invoke(Enterprise $enterprise, ?string $reason = null): bool
    {
        // An owner's live decision outranks a stranger's request. Recorded
        // rather than silently ignored, so the conflict is visible.
        if ($enterprise->publication_state === PublicationState::OptedIn) {
            VerificationEvent::record(
                $enterprise,
                'publication.removal_requested',
                null,
                array_filter(['reason' => $reason]),
                VerificationEvent::ACTOR_SYSTEM,
            );

            return false;
        }

        DB::transaction(function () use ($enterprise, $reason): void {
            $enterprise->update([
                'publication_state' => PublicationState::Withheld,
                'publication_decided_at' => Carbon::now(config('app.timezone')),
            ]);

            VerificationEvent::record(
                $enterprise,
                'publication.withheld',
                null,
                array_filter([
                    'reason' => $reason,
                    'source' => 'public_request',
                ]),
                VerificationEvent::ACTOR_SYSTEM,
            );
        });

        return true;
    }
}
