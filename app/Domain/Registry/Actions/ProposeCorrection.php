<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Media\Models\Media;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Enums\CorrectableField;
use App\Domain\Registry\Enums\CorrectionStatus;
use App\Domain\Registry\Models\CorrectionProposal;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A party says the register has something wrong.
 *
 * Nothing changes here. The proposal is recorded with what the register
 * currently says, what the party says it should say, and why, and it waits for
 * a person. That wait is the product: a register a business can edit is a
 * directory, and a directory is not what anybody is paying for.
 *
 * `current_value` is stamped now rather than read at decision time, so a
 * supervisor ruling in October sees what the party was looking at in August
 * rather than whatever the register happens to say that morning.
 */
final class ProposeCorrection
{
    public function __construct(private readonly ReadCurrentValue $current) {}

    public function __invoke(
        Party $party,
        PortalAccount $proposer,
        Enterprise $enterprise,
        CorrectableField $field,
        ?string $proposedValue,
        string $reason,
        ?Media $evidence = null,
    ): CorrectionProposal {
        if (trim($reason) === '') {
            throw new RuntimeException('Say what is wrong. Somebody has to read this and decide.');
        }

        $this->assertControls($party, $enterprise);

        return DB::transaction(function () use (
            $party, $proposer, $enterprise, $field, $proposedValue, $reason, $evidence
        ): CorrectionProposal {
            $live = CorrectionProposal::query()
                ->where('enterprise_id', $enterprise->id)
                ->where('field', $field->value)
                ->live()
                ->lockForUpdate()
                ->first();

            if ($live instanceof CorrectionProposal) {
                throw new RuntimeException(
                    'You already have a correction waiting on that field. Withdraw it first if it was wrong.',
                );
            }

            $currentValue = ($this->current)($enterprise, $field);

            if ((string) $currentValue === (string) $proposedValue) {
                throw new RuntimeException('That is already what the register says.');
            }

            $proposal = CorrectionProposal::query()->create([
                'party_id' => $party->id,
                'enterprise_id' => $enterprise->id,
                'proposed_by' => $proposer->id,
                'field' => $field,
                'current_value' => $currentValue,
                'proposed_value' => $proposedValue,
                'reason' => trim($reason),
                'evidence_media_id' => $evidence?->id,
                'status' => CorrectionStatus::Submitted,
            ]);

            VerificationEvent::record(
                $enterprise,
                'correction.proposed',
                null,
                [
                    'proposal_id' => $proposal->id,
                    'field' => $field->value,
                    'from' => $currentValue,
                    'to' => $proposedValue,
                    'party_id' => $party->id,
                    'has_evidence' => $evidence !== null,
                ],
                VerificationEvent::ACTOR_PARTY,
            );

            return $proposal;
        });
    }

    /**
     * Only the party that manages the listing may propose against it.
     *
     * Checked against the live control row rather than against the claim that
     * produced it: a claim approved and later transferred by a dispute must
     * stop granting this the moment it is transferred.
     */
    private function assertControls(Party $party, Enterprise $enterprise): void
    {
        $controls = PartyBusiness::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('party_id', $party->id)
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->exists();

        if (! $controls) {
            throw new RuntimeException('You do not manage this business.');
        }
    }
}
