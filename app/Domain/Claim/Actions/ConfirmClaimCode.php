<?php

declare(strict_types=1);

namespace App\Domain\Claim\Actions;

use App\Domain\Claim\Enums\ClaimEvidence;
use App\Domain\Claim\Models\Claim;
use App\Domain\Claim\Models\ClaimPhoneCode;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Checks the code, and if it holds, settles the claim.
 *
 * This is the strongest signal the platform has, and it is worth being precise
 * about why: an officer stood in front of the shop and wrote that number down.
 * Holding it is not proof of ownership in a legal sense, but it is proof of a
 * connection that a stranger reading a search result does not have, and it is
 * proof we generated ourselves rather than one the claimant supplied.
 */
final class ConfirmClaimCode
{
    public function __construct(
        private readonly DecideClaim $decisions,
    ) {}

    public function __invoke(Claim $claim, string $submitted): Claim
    {
        $code = ClaimPhoneCode::query()
            ->where('claim_id', $claim->id)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $code instanceof ClaimPhoneCode || ! $code->isLive()) {
            throw new RuntimeException('That code has expired. Ask for a new one.');
        }

        // Counted before the comparison, so a wrong guess costs an attempt even
        // if the request dies afterwards.
        $code->increment('attempts');

        if (! Hash::check(trim($submitted), $code->code_hash)) {
            $left = ClaimPhoneCode::MAX_ATTEMPTS - $code->attempts;

            VerificationEvent::recordForParty($claim->enterprise, 'claim.code_failed', $claim->party, [
                'claim_id' => $claim->id,
                'attempts_left' => max(0, $left),
            ]);

            throw new RuntimeException($left > 0
                ? "That code is not right. {$left} tries left."
                : 'That code is not right, and this code is now used up. Ask for a new one.');
        }

        return DB::transaction(function () use ($claim, $code): Claim {
            $code->forceFill(['consumed_at' => now()])->save();

            $claim->recordEvidence(ClaimEvidence::PhoneMatch, 'confirmed', [
                // The number itself is not written here. This row is read by
                // supervisors and exported into evidence packs, and the whole
                // point of the mask was that the number stays put.
                'code_id' => $code->id,
            ]);
            $claim->save();

            VerificationEvent::recordForParty($claim->enterprise, 'claim.phone_confirmed', $claim->party, [
                'claim_id' => $claim->id,
            ]);

            return $this->decisions->reassess($claim);
        });
    }
}
