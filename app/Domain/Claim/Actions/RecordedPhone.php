<?php

declare(strict_types=1);

namespace App\Domain\Claim\Actions;

use App\Domain\Claim\Models\Claim;
use App\Domain\Party\Actions\NormalisePhone;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;

/**
 * The number the officer wrote down, and the care taken with it.
 *
 * Two rules hold here. The number never leaves the server in full: the claimant
 * sees a mask and has to recognise it, because a claim form that printed the
 * number would be a way to read a phone number off any business in the register
 * by starting a claim and abandoning it. And every reveal of even the mask
 * appends an event, so a party working through fifty listings collecting last
 * four digits leaves a trail that looks exactly like what it is.
 */
final class RecordedPhone
{
    public function __construct(
        private readonly NormalisePhone $phones,
    ) {}

    /** The stored number in E.164, or null when the officer recorded none. */
    public function forClaim(Claim $claim): ?string
    {
        $raw = DB::scalar(<<<'SQL'
            SELECT o.phone
            FROM enterprise_observations o
            WHERE o.enterprise_id = ?
              AND o.phone IS NOT NULL
            ORDER BY o.observed_at DESC
            LIMIT 1
        SQL, [$claim->enterprise_id]);

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        // Normalised at read time rather than at capture time. The field client
        // is shipped and officers type what they see on the shutter, so the
        // column holds "0803 123 4567", "+234 803...", and worse. Rewriting the
        // observation to tidy it would edit an officer's record to suit us.
        return ($this->phones)($raw);
    }

    /**
     * What the claimant is shown, with the reveal recorded.
     *
     * Null means no number was captured, which is ordinary for a market stall
     * and simply means this claim takes the slower route.
     */
    public function hintFor(Claim $claim): ?string
    {
        $e164 = $this->forClaim($claim);

        if ($e164 === null) {
            return null;
        }

        VerificationEvent::recordForParty($claim->enterprise, 'claim.phone_hint_shown', $claim->party, [
            'claim_id' => $claim->id,
        ]);

        return $this->phones->masked($e164);
    }
}
