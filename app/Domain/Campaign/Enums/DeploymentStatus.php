<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Enums;

/** Whether somebody is currently out on a campaign. */
enum DeploymentStatus: string
{
    case Active = 'active';

    /** Off the campaign, and never removed from its history. */
    case StoodDown = 'stood_down';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::StoodDown => 'Stood down',
        };
    }
}
