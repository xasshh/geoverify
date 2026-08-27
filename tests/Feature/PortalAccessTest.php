<?php

declare(strict_types=1);

use App\Domain\Party\Actions\NormalisePhone;
use App\Domain\Party\Actions\RegisterParty;
use App\Domain\Party\Enums\PartyKind;
use App\Domain\Party\Enums\PartyRole;
use App\Domain\Party\Events\SignInCodeIssued;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Party\Models\SignInCode;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Portal access
|--------------------------------------------------------------------------
|
| A party gets in with a phone number and nothing else. What is tested here is
| that the door works, that it stays shut in the ways it must, and above all
| that a portal session can never become a staff one.
|
*/

/**
 * The code that was actually sent.
 *
 * Read from the issue event rather than by guessing at the stored hash: the
 * hash is bcrypt precisely so that nobody can work backwards from it, and a
 * test that brute forced six digits would take minutes to prove nothing.
 */
function latestCodeFor(string $phone): string
{
    foreach (array_reverse(issuedCodes()) as $event) {
        if ($event->phone === $phone) {
            return $event->code;
        }
    }

    throw new RuntimeException("No sign-in code was issued for {$phone}.");
}

/** @return list<SignInCodeIssued> */
function issuedCodes(): array
{
    /** @var list<SignInCodeIssued> $issued */
    $issued = [];

    Event::assertDispatched(SignInCodeIssued::class, function (SignInCodeIssued $event) use (&$issued): bool {
        $issued[] = $event;

        return true;
    });

    return $issued;
}

beforeEach(function (): void {
    // The delivery channel is not the subject here, and faking it is also what
    // makes the code readable to the test at all.
    Event::fake([SignInCodeIssued::class]);
});

it('takes a person from a phone number to a dashboard', function () {
    $this->post(route('portal.request-code'), ['phone' => '08031234567'])
        ->assertRedirect(route('portal.verify'));

    $phone = '+2348031234567';
    $code = latestCodeFor($phone);

    // Correct code, but nobody here yet: the number is proved and the person
    // is asked who they are, rather than being told they have no account.
    $this->post(route('portal.verify.submit'), ['code' => $code])
        ->assertRedirect(route('portal.register'));

    $this->post(route('portal.register.submit'), [
        'person_name' => 'Ngozi Eze',
        'display_name' => 'Mama Ngozi Provisions',
        'kind' => 'individual',
    ])->assertRedirect(route('portal.dashboard'));

    $party = Party::query()->firstOrFail();

    expect($party->display_name)->toBe('Mama Ngozi Provisions')
        ->and($party->primary_phone)->toBe($phone)
        ->and($party->identity_tier)->toBe('listed')
        ->and($party->code)->toMatch('/^NBD-/');

    // The person who registered owns it, with nobody to have invited them.
    $membership = PartyUser::query()->firstOrFail();
    expect($membership->role)->toBe(PartyRole::Owner)
        ->and($membership->accepted_at)->not->toBeNull();

    $this->get(route('portal.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('portal/Dashboard')
            ->where('parties.0.displayName', 'Mama Ngozi Provisions')
            ->where('parties.0.role', 'Owner'));
});

it('signs a returning owner straight in', function () {
    $party = app(RegisterParty::class)(
        '08031234567', 'Ngozi Eze', 'Mama Ngozi Provisions', PartyKind::Individual,
    );

    $this->post(route('portal.request-code'), ['phone' => '0803 123 4567'])
        ->assertRedirect(route('portal.verify'));

    $this->post(route('portal.verify.submit'), ['code' => latestCodeFor($party->primary_phone)])
        ->assertRedirect(route('portal.dashboard'));

    $this->get(route('portal.dashboard'))->assertOk();
});

it('treats one number written five ways as one person', function () {
    app(RegisterParty::class)(
        '08031234567', 'Ngozi Eze', 'Mama Ngozi Provisions', PartyKind::Individual,
    );

    foreach (['08031234567', '+2348031234567', '2348031234567', '8031234567', '0803 123 4567'] as $spelling) {
        expect(app(NormalisePhone::class)($spelling))->toBe('+2348031234567');
    }

    // The second registration is refused rather than creating a shadow account
    // that owns nothing and confuses its owner.
    expect(fn () => app(RegisterParty::class)(
        '+234 803 123 4567', 'Someone Else', 'Another Shop', PartyKind::Individual,
    ))->toThrow(RuntimeException::class);

    expect(PortalAccount::query()->count())->toBe(1);
});

it('burns a code after five wrong guesses, and lets the person ask for another', function () {
    $this->post(route('portal.request-code'), ['phone' => '08031234567']);
    $real = latestCodeFor('+2348031234567');
    $wrong = $real === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $ignored) {
        $this->post(route('portal.verify.submit'), ['code' => $wrong])
            ->assertSessionHasErrors('code');
    }

    // The correct code no longer works: this one is closed.
    $this->post(route('portal.verify.submit'), ['code' => $real])
        ->assertSessionHasErrors('code');

    expect(PortalAccount::query()->count())->toBe(0);

    // But the number is not locked out. Asking again is the way through, which
    // is the difference between a throttle and a punishment.
    $this->post(route('portal.request-code'), ['phone' => '08031234567'])
        ->assertRedirect(route('portal.verify'));

    $this->post(route('portal.verify.submit'), ['code' => latestCodeFor('+2348031234567')])
        ->assertRedirect(route('portal.register'));
});

it('stops accepting a code once a newer one is sent', function () {
    $this->post(route('portal.request-code'), ['phone' => '08031234567']);
    $first = latestCodeFor('+2348031234567');

    $this->post(route('portal.request-code'), ['phone' => '08031234567']);

    // Reading the older text must not sign anybody in, or "we only ever accept
    // the latest" is untrue.
    $this->post(route('portal.verify.submit'), ['code' => $first])
        ->assertSessionHasErrors('code');
});

it('refuses an expired code', function () {
    $this->post(route('portal.request-code'), ['phone' => '08031234567']);
    $code = latestCodeFor('+2348031234567');

    SignInCode::query()->latest('id')->firstOrFail()->update(['expires_at' => now()->subMinute()]);

    $this->post(route('portal.verify.submit'), ['code' => $code])
        ->assertSessionHasErrors('code');
});

it('appends every sign-in to the log', function () {
    $party = app(RegisterParty::class)(
        '08031234567', 'Ngozi Eze', 'Mama Ngozi Provisions', PartyKind::Individual,
    );

    $this->post(route('portal.request-code'), ['phone' => '08031234567']);
    $this->post(route('portal.verify.submit'), ['code' => latestCodeFor($party->primary_phone)]);

    $signIn = VerificationEvent::query()->where('event', 'portal.signed_in')->firstOrFail();
    $registered = VerificationEvent::query()->where('event', 'party.registered')->firstOrFail();

    expect($signIn->actor_type)->toBe(VerificationEvent::ACTOR_PARTY)
        ->and($registered->actor_type)->toBe(VerificationEvent::ACTOR_PARTY)
        ->and($registered->evidence['code'])->toBe($party->code)
        // Masked, never in full: an audit log is read by people who do not
        // need a customer's phone number.
        ->and($signIn->evidence['phone'])->toContain('•');
});

it('keeps the portal shut to anyone not signed in', function () {
    $this->get(route('portal.dashboard'))->assertRedirect(route('portal.sign-in'));
});

it('never lets a portal session reach staff ground', function () {
    $party = app(RegisterParty::class)(
        '08031234567', 'Ngozi Eze', 'Mama Ngozi Provisions', PartyKind::Individual,
    );

    // Signed in the way a person actually signs in, so the session carries the
    // portal guard's key and only that. actingAs with a guard name would make
    // portal the request's default guard and prove something weaker.
    $this->post(route('portal.request-code'), ['phone' => '08031234567']);
    $this->post(route('portal.verify.submit'), ['code' => latestCodeFor($party->primary_phone)]);

    $this->get(route('portal.dashboard'))->assertOk();

    // The console does not know this session at all: the web guard finds
    // nobody, and the request is sent to sign in as staff.
    $this->get('/console/coverage')->assertRedirect('/login');
    $this->get('/field')->assertRedirect('/login');
});

it('never lets a staff session reach the portal', function () {
    // A separate test rather than the tail of the last one, because both
    // sessions can live in one browser at once: they are different guards, not
    // competing claims on the same one. Proving the second property needs a
    // session that was never a portal session.
    $this->actingAs(person(Role::Supervisor))
        ->get(route('portal.dashboard'))
        ->assertRedirect(route('portal.sign-in'));

    $this->actingAs(person(Role::Officer, 'An officer'))
        ->get(route('portal.dashboard'))
        ->assertRedirect(route('portal.sign-in'));
});

it('suspends an account without deleting anything', function () {
    $party = app(RegisterParty::class)(
        '08031234567', 'Ngozi Eze', 'Mama Ngozi Provisions', PartyKind::Individual,
    );
    $account = PortalAccount::query()->where('phone', $party->primary_phone)->firstOrFail();
    $account->update(['status' => PortalAccount::STATUS_SUSPENDED]);

    $this->actingAs($account, 'portal')->get(route('portal.dashboard'))->assertForbidden();

    expect(PortalAccount::query()->count())->toBe(1)
        ->and(Party::query()->count())->toBe(1);
});

it('holds a party to one live owner', function () {
    $party = app(RegisterParty::class)(
        '08031234567', 'Ngozi Eze', 'Mama Ngozi Provisions', PartyKind::Individual,
    );

    $second = PortalAccount::query()->create([
        'name' => 'Someone Else',
        'phone' => '+2348039999999',
        'status' => PortalAccount::STATUS_ACTIVE,
    ]);

    // Losing track of who answers for a party would strand its listings and
    // its money, so the database refuses a second live owner outright.
    expect(fn () => PartyUser::query()->create([
        'party_id' => $party->id,
        'portal_account_id' => $second->id,
        'role' => PartyRole::Owner,
        'accepted_at' => now(),
    ]))->toThrow(QueryException::class);
});
