<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Three roles, and no more. Anything finer belongs in a policy.
 */
enum Role: string
{
    case Officer = 'officer';
    case Supervisor = 'supervisor';
    case Admin = 'admin';
    // Draws features of the land over imagery at a desk. Staff, so it lives
    // here, but it supervises nothing, captures nothing in the field and
    // administers nothing: every existing check answers false for it.
    case DeskDigitiser = 'desk_digitiser';

    public function label(): string
    {
        return match ($this) {
            self::Officer => 'Field officer',
            self::Supervisor => 'Supervisor',
            self::Admin => 'Administrator',
            self::DeskDigitiser => 'Desk digitiser',
        };
    }

    /** Assigning work, reviewing captures, and everything the console is for. */
    public function supervises(): bool
    {
        return $this === self::Supervisor || $this === self::Admin;
    }

    /** Holding assignments and capturing in the field. */
    public function capturesInTheField(): bool
    {
        return $this === self::Officer;
    }

    /**
     * The in-house view: escalations, the audit log, people, devices, mandates.
     *
     * Deliberately not implied by supervises(). A supervisor runs a mandate's
     * field work; an admin decides escalations raised against supervisors, adds
     * people and reads everything that happened. Folding the two together would
     * make the person who escalated a capture the person who rules on it, which
     * is the one thing escalation exists to prevent.
     */
    public function administers(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Drawing and importing area features at the desk (/desk).
     *
     * An administrator can too, so a small team need not create a second
     * account; a supervisor cannot, because a supervisor reviews officers'
     * work and drawing the work list they then send officers to check is a
     * different job.
     */
    public function digitises(): bool
    {
        return $this === self::DeskDigitiser || $this === self::Admin;
    }
}
