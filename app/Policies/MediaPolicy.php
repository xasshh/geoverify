<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Media\Models\Media;
use App\Models\User;

/**
 * A photograph is evidence attached to a capture, so it is visible to whoever
 * can see that capture: the officer who took it, and any supervisor.
 */
final class MediaPolicy
{
    public function view(User $user, Media $media): bool
    {
        return $user->supervises() || $media->captured_by === $user->id;
    }
}
