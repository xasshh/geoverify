<?php

declare(strict_types=1);

namespace App\Domain\Verification\Enums;

/**
 * The three questions a supervisor actually asks, in the order they ask them.
 *
 * Not "map, photos, form". A flag is pinned to the question it answers, because
 * "accuracy over 5 m on 2 fixes" means nothing next to a photograph and
 * everything next to the fix list.
 */
enum ReviewQuestion: string
{
    /** Was the officer there? */
    case Presence = 'presence';

    /** Is this the thing they say it is? */
    case Identification = 'identification';

    /** Does the record hold together? */
    case Plausibility = 'plausibility';

    public function label(): string
    {
        return match ($this) {
            self::Presence => 'Presence',
            self::Identification => 'Identification',
            self::Plausibility => 'Plausibility',
        };
    }
}
