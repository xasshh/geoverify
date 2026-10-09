<?php

declare(strict_types=1);

use App\Domain\Enumerate\Actions\DecideDeskCheck;
use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Party\Models\PortalAccount;
use App\Enums\Role;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

/**
 * Launch mode, decided 2026-10-09: Enumerate checks are free, the business
 * and investor portals are closed as coming soon, and portal accounts can
 * continue with Google.
 *
 * What matters: a free check moves no money at all, from placing to the desk
 * check; a closed portal answers "coming soon" to every page and nothing to
 * every write, while the account pages Enumerate shares keep working; and a
 * Google sign in is accepted only for an address Google has verified, and
 * never takes over an account linked to a different Google account.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    config()->set('services.registry.driver', 'fake');
    config()->set('services.sms.enabled', false);
});

function launchRequester(string $email = 'ada@example.ng'): PortalAccount
{
    $account = PortalAccount::query()->create(['name' => 'Ada', 'email' => $email, 'password' => 'a-long-password', 'status' => 'active']);
    $account->forceFill(['email_verified_at' => now()])->save();

    return $account;
}

function googleUser(string $id, string $email, bool $verified = true): GoogleUser
{
    $user = (new GoogleUser)->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified])->map([
        'id' => $id, 'email' => $email, 'name' => 'Ada Google',
    ]);

    return $user;
}

it('runs a free check from an empty wallet, and moves no money from placing to the desk check', function () {
    config()->set('geoverify.enumerate_free', true);
    $account = launchRequester();

    $request = enumerateCheck($account);

    expect($request->price_minor)->toBe(0)
        ->and($request->registry_fee_minor)->toBe(0)
        ->and($request->status)->toBe(RequestStatus::RegistryCheck);

    $decided = app(DecideDeskCheck::class)($request, person(Role::Supervisor), true, null);

    expect($decided->status)->toBe(RequestStatus::Passed)
        ->and(DB::table('ledger_entries')->where('enumerate_request_id', $request->id)->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);

    $this->actingAs($account, 'portal')
        ->get('/enumerate/verify')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('enumerateFree', true)->where('prices.tier1', 0));
});

it('still charges when checks are not free', function () {
    config()->set('geoverify.enumerate_free', false);

    expect(fn () => enumerateCheck(launchRequester(), Tier::Registry))
        ->toThrow(RuntimeException::class, 'does not hold enough');
});

it('closes the business portal as coming soon, keeping the account pages Enumerate shares', function () {
    config()->set('geoverify.surfaces.portal', false);
    $account = launchRequester();

    $this->get('/portal/sign-in')->assertRedirect('/enumerate/sign-in');
    $this->get('/portal/register')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('portal/Register')->where('audience', 'buyer')->where('portalOpen', false));
    $this->get('/portal/forgot-password')->assertOk();

    $this->actingAs($account, 'portal')->get('/portal')->assertRedirect('/enumerate');
    $this->actingAs($account, 'portal')->get('/portal/claim')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('public/ComingSoon')->where('surface', 'portal'));
    $this->actingAs($account, 'portal')->post('/portal/claim', [])->assertNotFound();
    $this->actingAs($account, 'portal')->get('/enumerate')->assertOk();

    // Signed out, the same.
    $this->post('/portal/sign-out');
    $this->get('/portal/register-business')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('public/ComingSoon'));

    // A registration while closed opens no business.
    $this->post('/portal/register', [
        'audience' => 'business', 'person_name' => 'Bola', 'email' => 'bola@example.ng',
        'password' => 'a-long-password', 'password_confirmation' => 'a-long-password',
        'display_name' => 'Bola Stores', 'kind' => 'individual',
    ])->assertRedirect('/portal/email/verify');
    expect(DB::table('parties')->count())->toBe(0);
});

it('closes the investor portal as coming soon', function () {
    config()->set('geoverify.surfaces.invest', false);

    $this->get('/invest/sign-in')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('public/ComingSoon')->where('surface', 'invest'));
    $this->post('/invest/sign-in', [])->assertNotFound();
});

it('opens and signs in an account with Google, already verified', function () {
    config()->set(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
    Socialite::shouldReceive('driver->user')->andReturn(googleUser('g-1', 'New@Example.ng'));

    $this->get('/auth/google/callback')->assertRedirect('/portal');

    $account = PortalAccount::query()->sole();
    expect($account->email)->toBe('new@example.ng')
        ->and($account->google_id)->toBe('g-1')
        ->and($account->isProved())->toBeTrue()
        ->and($account->password)->toBeNull();
});

it('links Google to an existing email account, and verifies it', function () {
    config()->set(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
    $existing = PortalAccount::query()->create(['name' => 'Ada', 'email' => 'ada@example.ng', 'password' => 'a-long-password', 'status' => 'active']);
    Socialite::shouldReceive('driver->user')->andReturn(googleUser('g-2', 'ada@example.ng'));

    $this->get('/auth/google/callback')->assertRedirect('/portal');

    expect(PortalAccount::query()->count())->toBe(1)
        ->and($existing->refresh()->google_id)->toBe('g-2')
        ->and($existing->isProved())->toBeTrue();
});

it('refuses a Google address Google has not verified, and one linked to another Google account', function () {
    config()->set(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);

    Socialite::shouldReceive('driver->user')->once()->andReturn(googleUser('g-3', 'x@example.ng', verified: false));
    $this->get('/auth/google/callback')->assertRedirect('/enumerate/sign-in')->assertSessionHasErrors('identifier');
    expect(PortalAccount::query()->count())->toBe(0);

    $linked = launchRequester('linked@example.ng');
    $linked->forceFill(['google_id' => 'g-original'])->save();
    Socialite::shouldReceive('driver->user')->once()->andReturn(googleUser('g-intruder', 'linked@example.ng'));
    $this->get('/auth/google/callback')->assertRedirect('/enumerate/sign-in')->assertSessionHasErrors('identifier');
    expect($linked->refresh()->google_id)->toBe('g-original');
    $this->assertGuest('portal');
});

it('offers no Google sign in on a server without Google credentials', function () {
    config()->set(['services.google.client_id' => '', 'services.google.client_secret' => '']);

    $this->get('/auth/google')->assertNotFound();
    $this->get('/auth/google/callback')->assertNotFound();
    $this->get('/enumerate/sign-in')->assertInertia(fn (AssertableInertia $page) => $page->where('googleSignIn', false));
});
