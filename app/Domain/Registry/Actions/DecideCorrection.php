<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Registry\Enums\CorrectableField;
use App\Domain\Registry\Enums\CorrectionStatus;
use App\Domain\Registry\Models\CorrectionProposal;
use App\Domain\Registry\Models\EnterpriseObservation;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A supervisor rules on a correction, and the original survives either way.
 *
 * Accepting does not edit the officer's observation. It writes a new one,
 * authored by the party, carrying everything the last observation said with the
 * corrected field changed, and moves the projection on `enterprises` to match.
 * March stays exactly as March was recorded, beside the party's account of it,
 * which is what lets the register still answer "what did an officer see" after
 * a business has corrected its own name three times.
 *
 * Placement is the exception and is deliberately not an observation. Which unit
 * a business occupies is a fact about the record rather than about a morning,
 * so it moves on the enterprise alone and nothing is appended.
 */
final class DecideCorrection
{
    public function __invoke(
        CorrectionProposal $proposal,
        User $reviewer,
        CorrectionStatus $outcome,
        string $note,
    ): CorrectionProposal {
        if (! $reviewer->supervises()) {
            throw new RuntimeException('Only a supervisor can decide a correction.');
        }

        if ($proposal->status !== CorrectionStatus::Submitted) {
            throw new RuntimeException('That correction has already been decided.');
        }

        if (! in_array($outcome, [CorrectionStatus::Accepted, CorrectionStatus::Rejected], true)) {
            throw new RuntimeException('A correction is either accepted or not.');
        }

        // Required on both paths. A rejection with no reason is a business told
        // no by a system, and an acceptance with no reason is a change to the
        // register that nobody signed.
        if (trim($note) === '') {
            throw new RuntimeException('Say why. The party is shown this.');
        }

        return DB::transaction(function () use ($proposal, $reviewer, $outcome, $note): CorrectionProposal {
            if ($outcome === CorrectionStatus::Accepted) {
                $this->apply($proposal, $reviewer);
            }

            $proposal->update([
                'status' => $outcome,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => Carbon::now(config('app.timezone')),
                'decision_note' => trim($note),
            ]);

            VerificationEvent::record(
                $proposal->enterprise,
                'correction.'.$outcome->value,
                $reviewer,
                [
                    'proposal_id' => $proposal->id,
                    'field' => $proposal->field->value,
                    'from' => $proposal->current_value,
                    'to' => $proposal->proposed_value,
                    'note' => trim($note),
                ],
            );

            return $proposal->refresh();
        });
    }

    /** Writes the correction into the register without overwriting anybody. */
    private function apply(CorrectionProposal $proposal, User $reviewer): void
    {
        $field = $proposal->field;
        $enterprise = $proposal->enterprise;
        $value = $this->cast($proposal);

        if ($field->isObserved()) {
            $this->appendObservation($proposal, $value);
        }

        if ($field->isProjected()) {
            // The projection follows the correction so ordinary reads stay
            // simple, exactly as it follows an officer's re-observation.
            $enterprise->update([$field->value => $value]);
        }
    }

    /**
     * A new observation, authored by the party rather than by an officer.
     *
     * Everything is carried forward from the latest observation so the new row
     * is a complete account rather than a fragment: an observation that only
     * held the one corrected field would read, later, as a visit where nothing
     * else was true.
     */
    private function appendObservation(CorrectionProposal $proposal, mixed $value): void
    {
        $latest = EnterpriseObservation::query()
            ->where('enterprise_id', $proposal->enterprise_id)
            ->latest('observed_at')
            ->first();

        if (! $latest instanceof EnterpriseObservation) {
            throw new RuntimeException('That listing has no observation to correct.');
        }

        $carried = [
            'trading_name' => $latest->trading_name,
            'registered_name' => $latest->registered_name,
            'sector_code' => $latest->sector_code,
            'subsector_code' => $latest->subsector_code,
            'scale_band' => $latest->scale_band,
            'employee_band' => $latest->employee_band,
            'operating_status' => $latest->operating_status,
            'years_at_location' => $latest->years_at_location,
            'phone' => $latest->phone,
            'email' => $latest->email,
            'website' => $latest->website,
            'opening_hours' => $latest->opening_hours,
            'signage_observed' => $latest->signage_observed,
        ];

        $carried[$proposal->field->value] = $value;

        /*
         * Written by hand rather than through the model, following the same
         * choice M4 made for a self-registration: recorded_by_party_id is
         * deliberately not mass assignable, because a request that could set it
         * could put a party's name on an officer's observation.
         *
         * captured_by stays null. The table's check constraint allows exactly
         * one author, which is what stops this from ever reading as field work.
         */
        DB::insert(<<<'SQL'
            INSERT INTO enterprise_observations (
                enterprise_id, recorded_by_party_id, captured_by, observed_at,
                trading_name, registered_name, sector_code, subsector_code,
                scale_band, employee_band, operating_status, years_at_location,
                phone, email, website, opening_hours, signage_observed,
                notes, status, client_uuid, created_at, updated_at
            )
            VALUES (?, ?, NULL, now(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'accepted', ?, now(), now())
        SQL, [
            $proposal->enterprise_id,
            $proposal->party_id,
            $carried['trading_name'],
            $carried['registered_name'],
            $carried['sector_code'],
            $carried['subsector_code'],
            $carried['scale_band'],
            $carried['employee_band'],
            $carried['operating_status'],
            $carried['years_at_location'],
            $carried['phone'],
            $carried['email'],
            $carried['website'],
            $carried['opening_hours'],
            $carried['signage_observed'],
            'Corrected by the business: '.$proposal->reason,
            (string) Str::uuid7(),
        ]);
    }

    /** The proposed value in the shape its column expects. */
    private function cast(CorrectionProposal $proposal): mixed
    {
        $value = $proposal->proposed_value;

        if ($value === null || $value === '') {
            return null;
        }

        // Floor is the one numeric field a party can correct, and it is signed:
        // ground is 0, a basement is negative.
        return $proposal->field === CorrectableField::Floor ? (int) $value : $value;
    }
}
