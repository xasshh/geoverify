<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Carbon;

/**
 * What happened to a business, in the words its owner would use.
 *
 * `verification_events` is the audit log, and the audit log is written for a
 * regulator and an admin: it carries officer ids, device ids, scores, ruling
 * notes and the internal names of state transitions. None of that belongs on a
 * business owner's dashboard, and some of it belongs to somebody else entirely.
 *
 * So this is an allowlist, not a filter. An event with no entry in the map is
 * not rendered, which means the failure mode of adding a new event type is that
 * it is invisible here until somebody writes a sentence for it. The other
 * ordering, a denylist, fails by disclosing whatever was added last.
 *
 * Evidence is never read. The sentence comes from the event name alone.
 */
final class ReadPartyActivity
{
    /**
     * The events a party may see about their own business, and the tone each
     * carries. Tones are the five the status vocabulary already fixes.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const VISIBLE = [
        'enterprise.captured' => ['An officer recorded this business', 'accepted'],
        'business.self_registered' => ['You added this business to the register', 'accepted'],
        'structure.re_observed' => ['An officer visited again', 'accepted'],
        'enterprise.re_observed' => ['An officer updated what the register holds', 'accepted'],
        'claim.submitted' => ['You claimed this business', 'progress'],
        'claim.phone_confirmed' => ['You proved the number on the record', 'accepted'],
        'claim.control_granted' => ['This business became yours to manage', 'accepted'],
        'claim.control_revoked' => ['Control of this business was transferred', 'rejected'],
        'claim.rejected' => ['A claim on this business was refused', 'rejected'],
        'claim.dispute_resolved' => ['A dispute over this business was decided', 'review'],
        'order.placed' => ['You ordered a verification visit', 'progress'],
        'order.paid' => ['Payment received', 'accepted'],
        'order.assigned' => ['An officer was assigned to your visit', 'review'],
        'order.completed' => ['The visit was accepted and the fee recognised', 'accepted'],
        'order.refunded' => ['A verification was refunded', 'rejected'],
        'publication.opted_in' => ['You published this listing', 'accepted'],
        'publication.withheld' => ['You took this listing out of the directory', 'idle'],
        'consent.granted' => ['You agreed to publication', 'accepted'],
        'consent.withdrawn' => ['You withdrew your agreement to publish', 'idle'],
        'certificate.downloaded' => ['A certificate was downloaded', 'idle'],
        'verification.published' => ['A checkable code was issued for this business', 'accepted'],
    ];

    /**
     * @return list<array{event: string, label: string, tone: string, at: string}>
     */
    public function forEnterprise(int $enterpriseId, string $morphClass, int $limit = 8): array
    {
        return VerificationEvent::query()
            ->where('subject_type', $morphClass)
            ->where('subject_id', $enterpriseId)
            ->whereIn('event', array_keys(self::VISIBLE))
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(static function (VerificationEvent $event): array {
                [$label, $tone] = self::VISIBLE[$event->event];

                return [
                    'event' => $event->event,
                    'label' => $label,
                    'tone' => $tone,
                    'at' => $event->occurred_at instanceof Carbon
                        ? $event->occurred_at->toIso8601String()
                        : (string) $event->occurred_at,
                ];
            })
            ->values()
            ->all();
    }
}
