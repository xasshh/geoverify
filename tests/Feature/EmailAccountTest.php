<?php

declare(strict_types=1);

use App\Domain\Enumerate\Actions\EnumerateContext;
use App\Domain\Enumerate\Actions\ManageOrganisations;
use App\Domain\Enumerate\Models\EnumerateMember;
use App\Domain\Party\Events\SignInCodeIssued;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use App\Mail\PortalActionMail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

/**
 * Portal accounts by email, with SMS switched off (decided 2026-10-09).
 *
 * What matters: nobody reaches an SMS path; an account opens nothing until its
 * email is proved by the link we sent; a link minted for one address never
 * proves another; "forgot password" says the same thing whether or not the
 * address is known; and an invitation by email ends with a verified account
 * that can accept it.
 */
beforeEach(function () {
    config(['services.sms.enabled' => false]);
    Mail::fake();
    Event::fake([SignInCodeIssued::class]);
});

/** The link in the last account email sent to an address. */
function mailedLink(string $email): string
{
    $url = null;

    Mail::assertSent(PortalActionMail::class, function (PortalActionMail $mail) use ($email, &$url): bool {
        if ($mail->hasTo($email)) {
            $url = $mail->url;
        }

        return true;
    });

    expect($url)->not->toBeNull();

    return (string) $url;
}

/** @return array<string, string> */
function businessRegistration(string $email = 'ada@shop.ng'): array
{
    return [
        'audience' => 'business',
        'person_name' => 'Ada Obi',
        'email' => $email,
        'password' => 'a-long-password',
        'password_confirmation' => 'a-long-password',
        'display_name' => 'Ada Provisions',
        'kind' => 'individual',
    ];
}

it('offers no way in by SMS, anywhere', function () {
    $this->get('/portal/sign-in')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('portal/SignIn')->where('smsEnabled', false));

    $this->post('/portal/sign-in', ['phone' => '08031234567'])->assertNotFound();
    $this->get('/portal/verify')->assertNotFound();
    $this->post('/portal/verify', ['code' => '123456'])->assertNotFound();

    Event::assertNotDispatched(SignInCodeIssued::class);
});

it('registers a business by email, and opens nothing until the email is proved', function () {
    $this->post('/portal/register', businessRegistration('Ada@Shop.ng'))
        ->assertRedirect('/portal/email/verify')
        ->assertSessionHasNoErrors();

    $account = PortalAccount::query()->sole();
    expect($account->email)->toBe('ada@shop.ng')
        ->and($account->phone)->toBeNull()
        ->and($account->isProved())->toBeFalse()
        ->and(PartyUser::query()->where('portal_account_id', $account->id)->sole()->party?->display_name)->toBe('Ada Provisions');

    // Signed in, but held at the notice.
    $this->get('/portal')->assertRedirect('/portal/email/verify');
    $this->get('/portal/email/verify')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('portal/VerifyEmail'));

    $this->get(mailedLink('ada@shop.ng'))->assertRedirect('/portal');

    expect($account->refresh()->isProved())->toBeTrue()
        ->and(VerificationEvent::query()->where('event', 'portal.email_verified')->count())->toBe(1);
    $this->get('/portal')->assertOk();

    Event::assertNotDispatched(SignInCodeIssued::class);
});

it('verifies from another device, with nobody signed in', function () {
    $this->post('/portal/register', businessRegistration());
    $link = mailedLink('ada@shop.ng');
    $this->post('/portal/sign-out');

    $this->get($link)->assertRedirect('/portal/sign-in');
    expect(PortalAccount::query()->sole()->isProved())->toBeTrue();
});

it('never proves an address with a link minted for another, or a tampered one', function () {
    $this->post('/portal/register', businessRegistration());
    $account = PortalAccount::query()->sole();
    $old = mailedLink('ada@shop.ng');

    // The address changes before the old link is followed.
    $account->forceFill(['email' => 'ada@elsewhere.ng'])->save();
    $this->get($old)->assertRedirect('/portal/sign-in');
    expect($account->refresh()->isProved())->toBeFalse();

    $forged = URL::temporarySignedRoute('portal.email.verify', now()->addDay(), ['account' => $account->id, 'hash' => sha1('ada@elsewhere.ng')]).'x';
    $this->get($forged)->assertRedirect('/portal/sign-in');
    expect($account->refresh()->isProved())->toBeFalse();
});

it('refuses a second account for the same email, whatever its case', function () {
    $this->post('/portal/register', businessRegistration());
    $this->post('/portal/sign-out');

    $this->post('/portal/register', businessRegistration('ADA@shop.ng'))->assertSessionHasErrors('email');
    expect(PortalAccount::query()->count())->toBe(1);
});

it('signs in with email and password', function () {
    $this->post('/portal/register', businessRegistration());
    $this->get(mailedLink('ada@shop.ng'));
    $this->post('/portal/sign-out');

    $this->post('/portal/sign-in/password', ['identifier' => 'ada@shop.ng', 'password' => 'wrong-password'])
        ->assertSessionHasErrors('identifier');

    $this->post('/portal/sign-in/password', ['identifier' => 'Ada@shop.ng', 'password' => 'a-long-password'])
        ->assertRedirect('/portal');
    $this->get('/portal')->assertOk();
});

it('answers "forgot password" the same for a stranger, and resets by the emailed link once', function () {
    $this->post('/portal/register', businessRegistration());
    $this->post('/portal/sign-out');
    Mail::fake();

    $unknown = $this->post('/portal/forgot-password', ['email' => 'nobody@shop.ng'])->assertRedirect();
    Mail::assertNothingSent();

    $known = $this->post('/portal/forgot-password', ['email' => 'ada@shop.ng'])->assertRedirect();
    expect($known->getSession()?->get('status'))->toBe($unknown->getSession()?->get('status'));

    parse_str((string) parse_url(mailedLink('ada@shop.ng'), PHP_URL_QUERY), $query);

    $reset = ['token' => $query['token'], 'email' => 'ada@shop.ng', 'password' => 'another-long-one', 'password_confirmation' => 'another-long-one'];
    $this->post('/portal/reset-password', $reset)->assertRedirect('/portal');

    // Following the link proved the address too.
    expect(PortalAccount::query()->sole()->isProved())->toBeTrue();

    $this->post('/portal/sign-out');
    $this->post('/portal/reset-password', $reset)->assertSessionHasErrors('password');
    $this->post('/portal/sign-in/password', ['identifier' => 'ada@shop.ng', 'password' => 'another-long-one'])->assertRedirect('/portal');
});

it('invites a person onto a business by email, who sets a password and accepts', function () {
    $this->post('/portal/register', businessRegistration());
    $this->get(mailedLink('ada@shop.ng'));

    $this->post('/portal/team', ['name' => 'Tunde Staff', 'email' => 'tunde@shop.ng', 'role' => 'manager'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $tunde = PortalAccount::query()->where('email', 'tunde@shop.ng')->sole();
    expect($tunde->password)->toBeNull()->and($tunde->isProved())->toBeFalse();

    $this->post('/portal/sign-out');
    parse_str((string) parse_url(mailedLink('tunde@shop.ng'), PHP_URL_QUERY), $query);
    $this->post('/portal/reset-password', [
        'token' => $query['token'], 'email' => 'tunde@shop.ng',
        'password' => 'tundes-password', 'password_confirmation' => 'tundes-password',
    ])->assertRedirect('/portal');

    expect($tunde->refresh()->isProved())->toBeTrue();

    $invite = PartyUser::query()->where('portal_account_id', $tunde->id)->sole();
    $this->post("/portal/team/{$invite->id}/accept")->assertRedirect();
    expect($invite->refresh()->accepted_at)->not->toBeNull();
});

it('gives an Enumerate seat by email, claimable only once that email is proved', function () {
    $this->post('/portal/register', businessRegistration());
    $owner = PortalAccount::query()->sole();
    $owner->forceFill(['email_verified_at' => now()])->save();

    $organisations = app(ManageOrganisations::class);
    $organisation = $organisations->open($owner, 'Ada Holdings Limited', null, null);
    $admin = EnumerateMember::query()->where('organisation_id', $organisation->id)->sole();
    expect($admin->email)->toBe('ada@shop.ng');

    $seat = $organisations->inviteByEmail($admin, 'Bola@Ada.ng', 'requester');
    expect(mailedLink('bola@ada.ng'))->toContain('/portal/register');

    $bola = PortalAccount::query()->create(['name' => 'Bola', 'email' => 'bola@ada.ng', 'password' => 'bolas-password', 'status' => 'active']);
    $context = app(EnumerateContext::class);

    // Unproved, the address claims nothing.
    expect($context->invitations($bola))->toBe([]);
    expect(fn () => $organisations->accept($seat, $bola))->toThrow(RuntimeException::class);

    $bola->forceFill(['email_verified_at' => now()])->save();
    expect($context->invitations($bola))->toHaveCount(1);
    expect($organisations->accept($seat, $bola)->accepted_at)->not->toBeNull();
});

it('leaves a claim to a supervisor, with no code to send', function () {
    $this->post('/portal/register', businessRegistration());
    $this->get(mailedLink('ada@shop.ng'));

    $this->post('/portal/claim/1/code')->assertNotFound();
    $this->post('/portal/claim/1/confirm', ['code' => '123456'])->assertNotFound();
});
