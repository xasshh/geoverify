<?php

declare(strict_types=1);

use App\Domain\Party\Models\PortalAccount;
use App\Domain\Staff\Actions\DecideAgentApplication;
use App\Domain\Staff\Models\AgentApplication;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;

/**
 * The field portal's door at /agent (2026-10-13).
 *
 * What matters: signing up makes an applicant, a portal account with an
 * application, and never a staff account; an applicant reaches their
 * application and nothing in the field client; staff sign in through Fortify
 * as before; and an approved agent signs in with the password they applied
 * with.
 */
it('sends /login on to /agent, and shows the sign in there', function () {
    $this->get('/login')->assertRedirect('/agent');
    $this->get('/agent')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login'));
    $this->get('/field')->assertRedirect('/login');
});

it('signs somebody up as an applicant, never as staff', function () {
    $this->post('/agent/sign-up', [
        'person_name' => 'Terna Iorliam',
        'email' => 'terna@example.ng',
        'password' => 'a-long-password',
        'password_confirmation' => 'a-long-password',
    ])->assertRedirect('/agent/apply');

    $account = PortalAccount::query()->where('email', 'terna@example.ng')->firstOrFail();

    expect(User::query()->where('email', 'terna@example.ng')->exists())->toBeFalse()
        ->and(AgentApplication::query()->where('portal_account_id', $account->id)->value('status'))->toBe(AgentApplication::DRAFT)
        ->and(Auth::guard('portal')->id())->toBe($account->id)
        ->and(Auth::guard('web')->check())->toBeFalse();

    // The field client stays on the staff guard.
    $this->get('/field')->assertRedirect('/login');
});

it('holds an applicant\'s changes until the email is confirmed', function () {
    $this->post('/agent/sign-up', [
        'person_name' => 'Terna Iorliam',
        'email' => 'terna@example.ng',
        'password' => 'a-long-password',
        'password_confirmation' => 'a-long-password',
    ]);

    $this->get('/agent/apply')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('frame.proved', false));
    $this->post('/agent/apply/device', ['ownership' => 'personal', 'power_bank' => true, 'gps_best_accuracy_m' => 5])
        ->assertRedirect('/agent/apply')
        ->assertSessionHasErrors('email');
});

it('will not open an applicant account for somebody already on the staff', function () {
    person(Role::Officer)->forceFill(['email' => 'staff@example.ng'])->save();

    $this->post('/agent/sign-up', [
        'person_name' => 'Somebody',
        'email' => 'staff@example.ng',
        'password' => 'a-long-password',
        'password_confirmation' => 'a-long-password',
    ])->assertSessionHasErrors('email');

    expect(PortalAccount::query()->where('email', 'staff@example.ng')->exists())->toBeFalse();
});

it('signs an applicant in to their application', function () {
    PortalAccount::query()->create(['name' => 'Ngozi', 'email' => 'ngozi@example.ng', 'password' => 'a-long-password', 'status' => 'active']);

    $this->post('/agent', ['email' => 'ngozi@example.ng', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
    expect(Auth::guard('portal')->check())->toBeFalse();

    $this->post('/agent', ['email' => 'ngozi@example.ng', 'password' => 'a-long-password'])->assertRedirect('/agent/apply');
    expect(Auth::guard('portal')->check())->toBeTrue()
        ->and(Auth::guard('web')->check())->toBeFalse();
});

it('signs staff in through Fortify, to their own home', function () {
    $officer = User::query()->create([
        'name' => 'Active officer', 'email' => 'active@geoverify.test', 'password' => 'correct-horse-battery',
        'role' => Role::Officer, 'status' => User::STATUS_ACTIVE,
    ]);

    $this->post('/agent', ['email' => 'active@geoverify.test', 'password' => 'not-it'])->assertSessionHasErrors('email');
    expect(Auth::guard('web')->check())->toBeFalse();

    $this->post('/agent', ['email' => 'active@geoverify.test', 'password' => 'correct-horse-battery'])->assertRedirect('/field');
    expect(Auth::guard('web')->id())->toBe($officer->id);
});

it('lets an approved agent in with the password they applied with', function () {
    $account = PortalAccount::query()->create(['name' => 'Ngozi', 'email' => 'ngozi@example.ng', 'password' => 'a-long-password', 'status' => 'active']);
    $account->forceFill(['email_verified_at' => now()])->save();
    $application = AgentApplication::query()->create([
        'reference' => 'AGT-TEST', 'portal_account_id' => $account->id, 'status' => AgentApplication::SUBMITTED,
        'surname' => 'Okafor', 'first_name' => 'Ngozi', 'submitted_at' => now(),
    ]);

    app(DecideAgentApplication::class)->approve(person(Role::Admin), $application, Role::Officer, ['buildings'], null, null);

    // From the applicant's session, Start work signs them out to sign in again.
    $this->actingAs($account, 'portal')->post('/agent/start-work')
        ->assertRedirect('/agent')
        ->assertSessionHas('email', 'ngozi@example.ng');
    expect(Auth::guard('portal')->check())->toBeFalse();

    $this->post('/agent', ['email' => 'ngozi@example.ng', 'password' => 'a-long-password'])->assertRedirect('/field');
    expect(Auth::guard('web')->user()?->email)->toBe('ngozi@example.ng');
});
