<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Enums;

/**
 * Where a campaign stands, and what it is allowed to become next.
 *
 * The transitions live here rather than in the screen that offers them. A status
 * dropdown would let somebody move a draft straight to active and skip the
 * approval that exists because activating is what puts officers on the road and
 * money on an invoice. Every move is a named act with a guard behind it.
 */
enum CampaignStatus: string
{
    /** Being written. Invisible to the client. */
    case Draft = 'draft';

    /** Submitted for approval. Still not the client's to see. */
    case PendingApproval = 'pending_approval';

    /** Signed off internally. Visible to the client, not yet running. */
    case Approved = 'approved';

    /** Running. Officers deployed, records coming in. */
    case Active = 'active';

    /** Stopped for now, and meant to resume. */
    case Paused = 'paused';

    /** Finished. The records stand and the dossier is the account of it. */
    case Completed = 'completed';

    /** Closed and filed. Read only, still in the client's list. */
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether the client who commissioned it can see it at all.
     *
     * A campaign being drafted or priced is our working document. Showing a
     * client a half written scope, or one that has not been approved, invites a
     * conversation about terms nobody has agreed yet.
     */
    public function visibleToClient(): bool
    {
        return match ($this) {
            self::Draft, self::PendingApproval => false,
            default => true,
        };
    }

    /** Whether officers should be collecting against it right now. */
    public function isLive(): bool
    {
        return $this === self::Active;
    }

    /** Whether anything about it can still change. */
    public function isSettled(): bool
    {
        return $this === self::Completed || $this === self::Archived;
    }

    /**
     * The moves allowed out of this state.
     *
     * @return list<self>
     */
    public function allows(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Archived],
            // Back to draft is a real outcome of review, not a failure state.
            self::PendingApproval => [self::Approved, self::Draft, self::Archived],
            self::Approved => [self::Active, self::Draft, self::Archived],
            self::Active => [self::Paused, self::Completed],
            self::Paused => [self::Active, self::Completed, self::Archived],
            self::Completed => [self::Archived],
            self::Archived => [],
        };
    }

    public function allowsMoveTo(self $next): bool
    {
        return in_array($next, $this->allows(), true);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
