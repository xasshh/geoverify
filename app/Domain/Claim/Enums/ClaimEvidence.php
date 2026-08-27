<?php

declare(strict_types=1);

namespace App\Domain\Claim\Enums;

/**
 * The signals a claimant can offer, and what each is worth.
 *
 * The strengths are the settled thresholds, not a scoring heuristic: two
 * combinations approve on their own and everything else is looked at by a
 * person. Written here as one table so the rule is in one place rather than
 * spread across the code that applies it.
 */
enum ClaimEvidence: string
{
    /** A code sent to the number the officer wrote down at the shop. */
    case PhoneMatch = 'phone_match';

    /** A CAC registration checked, and the claimant on the roster it returned. */
    case CacDirector = 'cac_director';

    /** A certificate, a tenancy, a utility bill, a photograph of the signage. */
    case Documents = 'documents';

    /** The claimant's device, near the recorded structure, at claim time. */
    case Proximity = 'proximity';

    public function label(): string
    {
        return match ($this) {
            self::PhoneMatch => 'Code sent to the recorded number',
            self::CacDirector => 'CAC director match',
            self::Documents => 'Documents',
            self::Proximity => 'Location at claim time',
        };
    }

    /**
     * Whether this evidence, on its own and confirmed, settles a claim.
     *
     * Phone match is the strongest and cheapest thing we hold, because an
     * officer captured it standing in the shop. The CAC path settles only when
     * all three of its parts hold, which is why it is checked rather than
     * declared here.
     */
    public function settlesAlone(): bool
    {
        return $this === self::PhoneMatch;
    }

    /**
     * Proximity is never decisive, whatever else is present.
     *
     * A device position is trivially falsifiable, and this platform exists
     * because we know that: the heaviest signal in the field confidence scorer
     * is a mock location provider, precisely because one is free and installs
     * in thirty seconds. Treating a self-reported position as proof of control
     * would contradict the argument the register is sold on.
     */
    public function isSupportingOnly(): bool
    {
        return $this === self::Proximity;
    }
}
