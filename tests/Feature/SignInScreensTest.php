<?php

declare(strict_types=1);

use App\Domain\Investment\Notifications\ResetInvestorPassword;
use App\Domain\Party\Actions\ManagePortalCredentials;
use App\Domain\Party\Events\SignInCodeIssued;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/**
 * The login mockups: the second way into the portal, getting it back, buyers,
 * and the investor door.
 *
 * What matters is that a password is never an easier way to an account nobody
 * proved, that the business ID answers the same for a stranger as for a wrong
 * password, and that nothing on a signed-out investor page names a business.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    Event::fake([SignInCodeIssued::class]);
});

it('signs a member in with the business ID and their own password, and each member with theirs', function () {
    $owner = claimant('Owner Person', '08036660001');
    $credentials = app(ManagePortalCredentials::class);
    $credentials->setPassword($owner['account'], 'owner password ten', 'test');

    $colleague = PortalAccount::query()->create(['name' => 'Colleague', 'phone' => '+2348036660002', 'status' => 'active']);
    PartyUser::query()->create([
        'party_id' => $owner['party']->id,
        'portal_account_id' => $colleague->id,
        'role' => 'manager',
        'accepted_at' => now(),
    ]);
    $credentials->setPassword($colleague, 'colleague password', 'test');

    $code = strtolower($owner['party']->code);

    $this->post('/portal/sign-in/password', ['identifier' => $code, 'password' => 'colleague password'])
        ->assertRedirect('/portal');
    expect(auth('portal')->id())->toBe($colleague->id);

    auth('portal')->logout();

    $this->post('/portal/sign-in/password', ['identifier' => $owner['party']->code, 'password' => 'owner password ten'])
        ->assertRedirect('/portal');
    expect(auth('portal')->id())->toBe($owner['account']->id);
});

it('gives a stranger\'s business ID the same answer as a wrong password', function () {
    $owner = claimant('Owner Person', '08036660003');
    app(ManagePortalCredentials::class)->setPassword($owner['account'], 'owner password ten', 'test');

    $this->post('/portal/sign-in/password', ['identifier' => $owner['party']->code, 'password' => 'not it at all'])
        ->assertSessionHasErrors('identifier');
    $wrong = session('errors')->first('identifier');

    $this->post('/portal/sign-in/password', ['identifier' => 'NBD-ZZZZ-ZZZZ-Z', 'password' => 'not it at all'])
        ->assertSessionHasErrors('identifier');

    expect(session('errors')->first('identifier'))->toBe($wrong);
    expect(auth('portal')->check())->toBeFalse();
});

it('will not let a password open an account that never set one', function () {
    $owner = claimant('No Password', '08036660004');

    $this->post('/portal/sign-in/password', ['identifier' => $owner['party']->code, 'password' => ''])
        ->assertSessionHasErrors();
    $this->post('/portal/sign-in/password', ['identifier' => $owner['party']->code, 'password' => 'anything at all'])
        ->assertSessionHasErrors('identifier');

    expect(auth('portal')->check())->toBeFalse();
});

it('resets a password only by proving the phone again, and never signs in on the code alone', function () {
    $owner = claimant('Forgetful Owner', '08036660005');

    $this->post('/portal/sign-in', ['phone' => '08036660005', 'intent' => 'reset'])->assertRedirect('/portal/verify');
    $this->post('/portal/verify', ['code' => latestCodeFor('+2348036660005')])->assertRedirect('/portal/reset-password');

    // Proved, but not signed in until the new password is chosen.
    expect(auth('portal')->check())->toBeFalse();

    $this->post('/portal/reset-password', ['password' => 'brand new password', 'password_confirmation' => 'brand new password'])
        ->assertRedirect('/portal');

    expect(auth('portal')->id())->toBe($owner['account']->id);

    auth('portal')->logout();
    $this->post('/portal/sign-in/password', ['identifier' => $owner['party']->code, 'password' => 'brand new password'])
        ->assertRedirect('/portal');
});

it('refuses to reset a number that has no account, and the reset form without a proved phone', function () {
    $this->get('/portal/reset-password')->assertRedirect('/portal/forgot-password');
    $this->post('/portal/reset-password', ['password' => 'brand new password', 'password_confirmation' => 'brand new password'])
        ->assertRedirect('/portal/forgot-password');

    $this->post('/portal/sign-in', ['phone' => '08036660099', 'intent' => 'reset']);
    $this->post('/portal/verify', ['code' => latestCodeFor('+2348036660099')])
        ->assertRedirect('/portal/sign-in');

    expect(PortalAccount::query()->where('phone', '+2348036660099')->exists())->toBeFalse();
});

it('resends a code only to the number already in the session', function () {
    $this->post('/portal/verify/resend')->assertRedirect('/portal/sign-in');

    $this->post('/portal/sign-in', ['phone' => '08036660006']);
    $this->post('/portal/verify/resend')->assertRedirect();

    $mine = array_filter(issuedCodes(), static fn (SignInCodeIssued $e): bool => $e->phone === '+2348036660006');
    expect($mine)->toHaveCount(2);
});

it('opens a buyer account with no business attached', function () {
    $this->post('/portal/sign-in', ['phone' => '08036660007', 'audience' => 'buyer']);
    $this->post('/portal/verify', ['code' => latestCodeFor('+2348036660007')])->assertRedirect('/portal/register');

    $this->post('/portal/register', ['audience' => 'buyer', 'person_name' => 'Tunde Buyer', 'email' => 'Tunde@Example.test'])
        ->assertRedirect('/portal');

    $account = PortalAccount::query()->where('phone', '+2348036660007')->sole();

    expect($account->email)->toBe('tunde@example.test')
        ->and(PartyUser::query()->where('portal_account_id', $account->id)->exists())->toBeFalse()
        ->and(auth('portal')->id())->toBe($account->id);
});

it('asks for the current password to change one, and not to set the first', function () {
    $owner = claimant('Settings Owner', '08036660008');

    $this->actingAs($owner['account'], 'portal')
        ->post('/portal/settings/password', ['password' => 'first password!', 'password_confirmation' => 'first password!'])
        ->assertSessionHasNoErrors();

    $this->actingAs($owner['account']->refresh(), 'portal')
        ->post('/portal/settings/password', ['password' => 'second password', 'password_confirmation' => 'second password'])
        ->assertSessionHasErrors('current_password');

    $this->actingAs($owner['account']->refresh(), 'portal')
        ->post('/portal/settings/password', [
            'current_password' => 'first password!',
            'password' => 'second password',
            'password_confirmation' => 'second password',
        ])->assertSessionHasNoErrors();
});

it('resets an investor password by emailed link, on its own broker', function () {
    Notification::fake();
    $investor = investor();

    $this->post('/invest/forgot-password', ['email' => 'nobody@nowhere.test'])->assertSessionHas('status');
    $this->post('/invest/forgot-password', ['email' => $investor->email])->assertSessionHas('status');

    $token = null;
    Notification::assertSentTo($investor, ResetInvestorPassword::class, function (ResetInvestorPassword $n) use (&$token, $investor): bool {
        $url = $n->toMail($investor)->actionUrl;
        $token = basename((string) parse_url($url, PHP_URL_PATH));

        return str_contains($url, '/invest/reset-password/');
    });

    $this->post('/invest/reset-password', [
        'token' => $token,
        'email' => $investor->email,
        'password' => 'a fresh password',
        'password_confirmation' => 'a fresh password',
    ])->assertRedirect('/invest/sign-in');

    $this->post('/invest/sign-in', ['email' => $investor->email, 'password' => 'a fresh password'])->assertRedirect('/invest');

    // The staff broker never saw it.
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('says plainly when a firm has no single sign-on yet', function () {
    $this->post('/invest/sso', ['email' => 'kemi@harbourcapital.com'])
        ->assertSessionHasErrors(['email' => "Single sign-on is not set up for harbourcapital.com yet. Sign in with your email and password, or ask us to connect your firm's identity provider."]);

    config()->set('geoverify.investor_sso', ['harbourcapital.com' => 'https://idp.harbourcapital.test/start']);

    $this->post('/invest/sso', ['email' => 'kemi@harbourcapital.com'])
        ->assertRedirect('https://idp.harbourcapital.test/start');
});

it('names no business on the signed-out investor page', function () {
    $ground = sweptGround();
    publishedOpportunity($ground, 'Secret Grain Millers', '08036660010');

    $page = $this->get('/invest/sign-in')->assertOk();

    expect($page->getContent())->not->toContain('Secret Grain Millers');
    $page->assertInertia(fn ($p) => $p->has('featured.score')->missing('featured.name'));
});

it('shows the new front doors', function () {
    $this->get('/portal/sign-in')->assertOk()->assertInertia(fn ($p) => $p->component('portal/SignIn'));
    $this->get('/portal/forgot-password')->assertOk();
    $this->get('/invest/sso')->assertOk();
    $this->get('/invest/forgot-password')->assertOk();
    $this->get('/invest/reset-password/some-token?email=a@b.test')->assertOk();
});
