<?php

declare(strict_types=1);

namespace App\Domain\Investment\Actions;

use App\Domain\Investment\Models\InvestorInterest;
use App\Domain\Investment\Models\InvestorNote;
use App\Domain\Investment\Models\InvestorUser;
use App\Domain\Investment\Models\Opportunity;
use App\Domain\Investment\Models\WatchlistEntry;
use App\Domain\Verification\Models\VerificationEvent;

/**
 * What an organisation keeps for itself about an opportunity: whether it is
 * watching, whether it has said it is interested, and its notes.
 *
 * Watching and notes are private to the organisation. Interest is the one thing
 * the business learns, as a count, because telling it is the point.
 */
final class KeepInvestorRecords
{
    /** @return bool whether the organisation is now watching */
    public function toggleWatch(Opportunity $opportunity, InvestorUser $investor): bool
    {
        $entry = WatchlistEntry::query()->firstOrNew([
            'investor_organisation_id' => $investor->investor_organisation_id,
            'opportunity_id' => $opportunity->id,
        ]);

        $watching = ! $entry->exists || $entry->getAttribute('removed_at') !== null;

        $entry->fill([
            'added_by' => $investor->id,
            'removed_at' => $watching ? null : now(),
        ])->save();

        return $watching;
    }

    public function expressInterest(Opportunity $opportunity, InvestorUser $investor, ?string $message): void
    {
        $interest = InvestorInterest::query()->firstOrNew([
            'investor_organisation_id' => $investor->investor_organisation_id,
            'opportunity_id' => $opportunity->id,
        ]);

        if ($interest->exists) {
            return;
        }

        $interest->fill(['expressed_by' => $investor->id, 'message' => $message])->save();

        VerificationEvent::recordForInvestor($opportunity, 'opportunity.interest_expressed', $investor);
    }

    public function saveNote(Opportunity $opportunity, InvestorUser $investor, string $body): void
    {
        InvestorNote::query()->updateOrCreate(
            [
                'investor_organisation_id' => $investor->investor_organisation_id,
                'opportunity_id' => $opportunity->id,
            ],
            ['body' => $body, 'updated_by' => $investor->id],
        );
    }
}
