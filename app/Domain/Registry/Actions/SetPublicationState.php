<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Enums\PublicationState;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A party decides whether its listing may be shown outside this system.
 *
 * The only way `publication_state` ever moves. Enumeration is not consent to
 * publication: an officer walked up to a shop and recorded what was there, and
 * nobody asked the owner whether they wanted to be in a public directory.
 *
 * Revocation takes effect on the row, immediately, with no grace period and no
 * queue. A party that changes its mind has changed its mind, and a system that
 * kept publishing for another hour would be publishing without consent for an
 * hour.
 *
 * Both directions are appended to the log. "When did they agree" and "when did
 * they withdraw" are the two questions a regulator asks, and neither can be
 * answered by a column that only holds the current answer.
 */
final class SetPublicationState
{
    public function __invoke(
        Party $party,
        PortalAccount $actor,
        Enterprise $enterprise,
        PublicationState $state,
    ): Enterprise {
        $controls = PartyBusiness::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('party_id', $party->id)
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->exists();

        if (! $controls) {
            throw new RuntimeException('You do not manage this business.');
        }

        // Private is where a record starts, not somewhere a party moves to. A
        // party who no longer wants to be published has withheld, which is a
        // different and louder fact than never having been asked.
        if ($state === PublicationState::Private) {
            throw new RuntimeException('Choose whether to publish or to keep the listing off the register.');
        }

        $was = $enterprise->publication_state;

        if ($was === $state) {
            return $enterprise;
        }

        return DB::transaction(function () use ($enterprise, $party, $actor, $state, $was): Enterprise {
            $enterprise->update([
                'publication_state' => $state,
                'publication_decided_at' => Carbon::now(config('app.timezone')),
            ]);

            VerificationEvent::record(
                $enterprise,
                $state->publishable() ? 'publication.opted_in' : 'publication.withheld',
                null,
                [
                    'from' => $was->value,
                    'to' => $state->value,
                    'party_id' => $party->id,
                    'account_id' => $actor->id,
                ],
                VerificationEvent::ACTOR_PARTY,
            );

            return $enterprise->refresh();
        });
    }
}
