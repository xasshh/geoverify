<?php

declare(strict_types=1);

namespace App\Domain\Investment\Actions;

use App\Domain\Investment\Enums\Seeking;
use App\Domain\Investment\Models\Opportunity;
use App\Domain\Party\Models\Party;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Models\VerificationEvent;

/**
 * A business writes, publishes and withdraws what it tells investors.
 *
 * Control of the business is checked by the caller before anything here runs.
 * Publishing is the business's consent to be read by verified investors;
 * withdrawing keeps the row and removes it from every investor surface on the
 * next request, because ReadOpportunities asks for published and nothing else.
 */
final class ManageOpportunity
{
    /**
     * @param  array{seeking: string, ticket_size_naira?: int|null, use_of_funds?: string|null, summary?: string|null, operating_since?: int|null, staff_on_site?: string|null, premises?: string|null}  $input
     */
    public function save(Enterprise $enterprise, Party $party, array $input): Opportunity
    {
        $opportunity = $this->current($enterprise) ?? new Opportunity([
            'enterprise_id' => $enterprise->id,
            'status' => Opportunity::STATUS_DRAFT,
        ]);

        $opportunity->fill([
            'party_id' => $party->id,
            'seeking' => Seeking::from($input['seeking']),
            'ticket_size_minor' => ($input['ticket_size_naira'] ?? null) === null ? null : (int) $input['ticket_size_naira'] * 100,
            'use_of_funds' => $input['use_of_funds'] ?? null,
            'summary' => $input['summary'] ?? null,
            'operating_since' => $input['operating_since'] ?? null,
            'staff_on_site' => $input['staff_on_site'] ?? null,
            'premises' => $input['premises'] ?? null,
        ])->save();

        VerificationEvent::recordForParty($enterprise, 'opportunity.saved', $party, ['opportunity_id' => $opportunity->id]);

        return $opportunity;
    }

    public function publish(Opportunity $opportunity, Party $party): void
    {
        $opportunity->forceFill([
            'status' => Opportunity::STATUS_PUBLISHED,
            'published_at' => $opportunity->published_at ?? now(),
        ])->save();

        VerificationEvent::recordForParty($opportunity->enterprise, 'opportunity.published', $party, ['opportunity_id' => $opportunity->id]);
    }

    public function withdraw(Opportunity $opportunity, Party $party): void
    {
        $opportunity->forceFill([
            'status' => Opportunity::STATUS_WITHDRAWN,
            'withdrawn_at' => now(),
        ])->save();

        VerificationEvent::recordForParty($opportunity->enterprise, 'opportunity.withdrawn', $party, ['opportunity_id' => $opportunity->id]);
    }

    public function current(Enterprise $enterprise): ?Opportunity
    {
        return Opportunity::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('status', '<>', Opportunity::STATUS_WITHDRAWN)
            ->first();
    }
}
