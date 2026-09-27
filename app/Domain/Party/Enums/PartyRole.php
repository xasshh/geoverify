<?php

declare(strict_types=1);

namespace App\Domain\Party\Enums;

/**
 * What a person may do on behalf of a party.
 *
 * Three levels rather than two, because the middle one is the common case: a
 * shop owner's son who handles the paperwork should be able to order a
 * verification without also being able to hand the business to somebody else.
 */
enum PartyRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::Viewer => 'Viewer',
        };
    }

    /** Only an owner can change who else may act. */
    public function managesAccess(): bool
    {
        return $this === self::Owner;
    }

    /** Ordering verification spends the party's money. */
    public function spends(): bool
    {
        return $this !== self::Viewer;
    }

    /**
     * Claiming acquires a listing, which is closer to signing for something
     * than to editing it. A viewer who could claim would be able to bring the
     * party liabilities it never agreed to.
     */
    public function claims(): bool
    {
        return $this !== self::Viewer;
    }

    /** Marking an order sent tells a buyer their goods are on the way. */
    public function fulfils(): bool
    {
        return $this !== self::Viewer;
    }

    /**
     * Taking money out, or changing the account it goes to. An owner only: a
     * manager who could redirect payouts could empty the business.
     */
    public function withdraws(): bool
    {
        return $this === self::Owner;
    }

    /** Proposing a correction changes what the register says. */
    public function proposesChanges(): bool
    {
        return $this !== self::Viewer;
    }
}
