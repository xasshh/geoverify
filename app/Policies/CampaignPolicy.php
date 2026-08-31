<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\ClientUser;
use App\Models\User;

/**
 * Who may see and change a campaign.
 *
 * Three kinds of person reach a campaign and they arrive on three different
 * guards, so every method here has to answer for whichever turned up. Staff are
 * `User`; a commissioning client's administrator is a `ClientUser`. There is no
 * shared base class on purpose, and the union type is the price of that
 * separation being real rather than nominal.
 *
 * Authorisation is here, and the query scopes are in the model. Both, not
 * either: the scope keeps another organisation's rows out of a list, and the
 * policy makes a direct hit on an id somebody else owns a 403 rather than a
 * silent empty page.
 */
final class CampaignPolicy
{
    public function viewAny(User|ClientUser $actor): bool
    {
        return $actor instanceof ClientUser
            ? $actor->canSignIn()
            : ($actor->supervises() || $actor->capturesInTheField());
    }

    public function view(User|ClientUser $actor, Campaign $campaign): bool
    {
        if ($actor instanceof ClientUser) {
            // Their own organisation, and only once it is theirs to see. A
            // campaign still being drafted or priced is our working document.
            return $actor->canSignIn()
                && $actor->client_organisation_id === $campaign->client_organisation_id
                && $campaign->status->visibleToClient();
        }

        if ($actor->administers() || $actor->supervises()) {
            return true;
        }

        // An officer sees the exercises they are actually out on, and no others.
        return $actor->capturesInTheField()
            && $campaign->deployments()
                ->where('user_id', $actor->id)
                ->whereNull('unassigned_at')
                ->exists();
    }

    /** Writing a campaign is an administrator's act, start to finish. */
    public function create(User|ClientUser $actor): bool
    {
        return $actor instanceof User && $actor->administers();
    }

    public function update(User|ClientUser $actor, Campaign $campaign): bool
    {
        return $actor instanceof User
            && $actor->administers()
            && ! $campaign->status->isSettled();
    }

    /** Approve, activate, pause, complete, archive. The guards are in the action. */
    public function transition(User|ClientUser $actor, Campaign $campaign): bool
    {
        return $actor instanceof User && $actor->administers();
    }

    /**
     * Contract value, payment state, internal notes.
     *
     * Named separately so that "can this person see the campaign" and "can this
     * person see what it is worth" can never be the same question by accident.
     */
    public function viewCommercials(User|ClientUser $actor, Campaign $campaign): bool
    {
        return $actor instanceof User && $actor->administers();
    }

    /** Deploying and standing down officers. */
    public function manageDeployment(User|ClientUser $actor, Campaign $campaign): bool
    {
        return $actor instanceof User
            && ($actor->administers() || $actor->supervises())
            && ! $campaign->status->isSettled();
    }
}
