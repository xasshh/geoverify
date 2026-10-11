<?php

declare(strict_types=1);

use App\Domain\Party\Models\PortalAccount;
use Inertia\Testing\AssertableInertia;

/**
 * The Help page: the guide videos, behind the same door as the rest of
 * Enumerate, and home telling a newcomer how many checks they have run so it
 * can offer the tour.
 */
function helpAccount(): PortalAccount
{
    $account = PortalAccount::query()->create(['name' => 'Ada', 'email' => 'ada@help.ng', 'password' => 'a-long-password', 'status' => 'active']);
    $account->forceFill(['email_verified_at' => now()])->save();

    return $account;
}

it('shows the guide videos to a signed in person', function () {
    $this->actingAs(helpAccount(), 'portal')
        ->get('/enumerate/help')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('enumerate/Help')->has('frame'));
});

it('sends somebody signed out to the Enumerate sign in', function () {
    $this->get('/enumerate/help')->assertRedirect('/enumerate/sign-in');
});

it('tells home a newcomer has run no checks, so it can offer the tour', function () {
    $this->actingAs(helpAccount(), 'portal')
        ->get('/enumerate')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('enumerate/Home')
            ->where('counts.inProgress', 0)
            ->where('counts.completed', 0)
            ->where('agentApplication', null));
});
