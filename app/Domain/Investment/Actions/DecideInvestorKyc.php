<?php

declare(strict_types=1);

namespace App\Domain\Investment\Actions;

use App\Domain\Investment\Models\InvestorOrganisation;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use InvalidArgumentException;

/**
 * An admin rules on an investor organisation: verified, or suspended.
 *
 * What was checked is the admin's judgement and is written in the note. The
 * system records who decided and when, and a suspension takes effect on the
 * next request of every person signed in for that organisation.
 */
final class DecideInvestorKyc
{
    public function __invoke(InvestorOrganisation $organisation, string $decision, User $admin, ?string $note): void
    {
        if (! in_array($decision, [InvestorOrganisation::KYC_VERIFIED, InvestorOrganisation::KYC_SUSPENDED], true)) {
            throw new InvalidArgumentException('A KYC decision is verified or suspended.');
        }

        $organisation->forceFill([
            'kyc_status' => $decision,
            'kyc_decided_by' => $admin->id,
            'kyc_decided_at' => now(),
            'kyc_note' => $note,
        ])->save();

        VerificationEvent::record($organisation, 'investor.kyc_'.$decision, $admin, ['note' => $note]);
    }
}
