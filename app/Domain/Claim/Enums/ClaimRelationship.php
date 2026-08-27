<?php

declare(strict_types=1);

namespace App\Domain\Claim\Enums;

/**
 * What the claimant says they are to the business.
 *
 * Asserted, never inferred, and recorded as stated. If a dispute follows, what
 * somebody claimed to be at the time is part of the record.
 */
enum ClaimRelationship: string
{
    case Owner = 'owner';
    case Director = 'director';
    case AuthorisedAgent = 'authorised_agent';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'I own it',
            self::Director => 'I am a director',
            self::AuthorisedAgent => 'I act for the owner',
        };
    }

    /** The bare role, for lines that are already about the reader. */
    public function noun(): string
    {
        return match ($this) {
            self::Owner => 'owner',
            self::Director => 'director',
            self::AuthorisedAgent => 'authorised agent',
        };
    }

    /**
     * The same assertion, said about somebody rather than by them.
     *
     * A separate string rather than a lowercased label, because lowercasing
     * "I own it" produces "i own it". Two audiences read this: the claimant,
     * who wrote it in the first person, and a supervisor, who needs it in the
     * third. Neither is a transformation of the other.
     */
    public function assertion(): string
    {
        return match ($this) {
            self::Owner => 'owns it',
            self::Director => 'is a director',
            self::AuthorisedAgent => 'acts for the owner',
        };
    }

    /**
     * Only a director can be checked against a CAC roster.
     *
     * An owner of an unregistered market stall has no roster to appear on, and
     * an agent appears on nobody's.
     */
    public function checkableAgainstRoster(): bool
    {
        return $this === self::Director;
    }
}
