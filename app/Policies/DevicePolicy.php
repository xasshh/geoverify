<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Field\Models\Device;
use App\Models\User;

final class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->supervises();
    }

    public function view(User $user, Device $device): bool
    {
        return $user->supervises() || $device->user_id === $user->id;
    }

    /** Revoking a handset is a supervisor's call, never the holder's. */
    public function revoke(User $user, Device $device): bool
    {
        return $user->supervises() && $device->status === Device::STATUS_ACTIVE;
    }
}
