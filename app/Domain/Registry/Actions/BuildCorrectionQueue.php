<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Registry\Enums\CorrectableField;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Corrections waiting on a supervisor, oldest first.
 *
 * Oldest first, not worst first, which is the opposite of the observation
 * queue. A capture is triaged by how suspicious it looks because the risk is
 * that something false enters the register. A correction is somebody standing
 * at the counter having told us we have their own name wrong, and the cost of
 * that is measured in how long they have been standing there.
 */
final class BuildCorrectionQueue
{
    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(): array
    {
        $rows = DB::select(<<<'SQL'
            select
                proposals.id,
                proposals.field,
                proposals.current_value,
                proposals.proposed_value,
                proposals.reason,
                proposals.created_at,
                proposals.evidence_media_id,
                enterprises.id as enterprise_id,
                enterprises.trading_name,
                structures.origin,
                ward.name as ward,
                parties.code as party_code,
                parties.display_name as party_name,
                -- What this party has asked for before, and how it went. A
                -- first correction and a fifth are not the same request, and a
                -- supervisor should not have to go looking to find that out.
                (select count(*) from correction_proposals prior
                  where prior.party_id = proposals.party_id
                    and prior.status = 'accepted') as accepted_before,
                (select count(*) from correction_proposals prior
                  where prior.party_id = proposals.party_id
                    and prior.status = 'rejected') as rejected_before
            from correction_proposals proposals
            join enterprises on enterprises.id = proposals.enterprise_id
            join structures on structures.id = enterprises.structure_id
            join parties on parties.id = proposals.party_id
            left join admin_boundaries ward on ward.id = structures.ward_id
            where proposals.status = 'submitted'
            order by proposals.created_at
        SQL);

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'field' => (string) $row->field,
            'fieldLabel' => CorrectableField::from((string) $row->field)->label(),
            'currentValue' => $row->current_value,
            'proposedValue' => $row->proposed_value,
            'reason' => (string) $row->reason,
            'proposedAt' => Carbon::parse((string) $row->created_at)->toIso8601String(),
            'hasEvidence' => $row->evidence_media_id !== null,
            'enterprise' => [
                'id' => (int) $row->enterprise_id,
                'tradingName' => (string) $row->trading_name,
                'ward' => $row->ward,
                'selfRegistered' => $row->origin === 'self_registered',
            ],
            'party' => [
                'code' => (string) $row->party_code,
                'name' => (string) $row->party_name,
                'acceptedBefore' => (int) $row->accepted_before,
                'rejectedBefore' => (int) $row->rejected_before,
            ],
        ], $rows);
    }
}
