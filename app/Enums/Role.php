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

    public function label(): string
    {
        return match ($this) {
            self::Officer => 'Field officer',
            self::Supervisor => 'Supervisor',
            self::Admin => 'Administrator',
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
}
