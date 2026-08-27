<?php

declare(strict_types=1);

namespace App\Domain\Party\Enums;

/**
 * What a party is, declared at registration and never guessed.
 *
 * It decides which identity evidence applies: an individual proves a NIN
 * through a licensed channel, a company proves a CAC registration which is
 * public record. Getting this wrong means asking a trader for a document that
 * does not exist for them.
 */
enum PartyKind: string
{
    case Individual = 'individual';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Company => 'Company',
        };
    }

    /** What we ask this kind of party to prove about itself. */
    public function identityKind(): string
    {
        return match ($this) {
            self::Individual => 'nin',
            self::Company => 'cac',
        };
    }
}
