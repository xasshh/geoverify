<?php

declare(strict_types=1);

use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\ClientOrganisation;
use App\Domain\Campaign\Models\ClientUser;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use Illuminate\Support\Facades\Hash;

/**
 * The clients screen: the commissioning bodies and their logins.
 *
 * Before it, a client could only come from a seeder, so a fresh server could
 * not hold a campaign at all. What matters: only administrators reach it, a
 * short code is fixed once a campaign code carries it, nothing is deleted, and
 * a suspended login or organisation is refused at the door.
 */
it('keeps the clients screen to administrators', function () {
    $this->actingAs(person(Role::Supervisor))->get('/admin/clients')->assertForbidden();
    $this->actingAs(person(Role::Supervisor))->post('/admin/clients', ['name' => 'X', 'short_code' => 'XX'])->assertForbidden();

    $this->actingAs(person(Role::Admin))->get('/admin/clients')->assertOk();
});

it('adds a client, upper cases its short code, and refuses a duplicate', function () {
    $admin = person(Role::Admin);

    $this->actingAs($admin)
        ->post('/admin/clients', [
            'name' => 'Benue State Ministry of Lands',
            'short_code' => 'bnsg',
            'contact_email' => 'Lands@Benue.gov.ng',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $org = ClientOrganisation::query()->where('short_code', 'BNSG')->firstOrFail();

    expect($org->name)->toBe('Benue State Ministry of Lands')
        ->and($org->contact_email)->toBe('lands@benue.gov.ng')
        ->and($org->status)->toBe(ClientOrganisation::STATUS_ACTIVE)
        ->and(VerificationEvent::query()->where('event', 'client.created')->where('subject_id', $org->id)->exists())->toBeTrue();

    $this->actingAs($admin)
        ->post('/admin/clients', ['name' => 'Someone else', 'short_code' => 'BNSG'])
        ->assertSessionHasErrors(['short_code' => 'Another client already uses BNSG.']);

    $this->actingAs($admin)
        ->post('/admin/clients', ['name' => 'Bad code', 'short_code' => '9X'])
        ->assertSessionHasErrors('short_code');
});

it('lets the campaign form use a client the moment it exists', function () {
    $admin = person(Role::Admin);
    $org = ClientOrganisation::factory()->create(['short_code' => 'BNSG']);

    $this->actingAs($admin)
        ->post('/admin/campaigns', [
            'client_organisation_id' => $org->id,
            'name' => 'Benue land cover',
            'subject_type' => 'Land and water',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Campaign::query()->where('client_organisation_id', $org->id)->value('code'))->toStartWith('BNSG-');
});

it('fixes a short code once a campaign code carries it', function () {
    $admin = person(Role::Admin);
    $org = ClientOrganisation::factory()->create(['short_code' => 'BNSG', 'name' => 'Old name']);

    // Free to change while nothing carries it.
    $this->actingAs($admin)->put("/admin/clients/{$org->id}", ['name' => 'Old name', 'short_code' => 'BENUE'])->assertSessionHasNoErrors();
    expect($org->refresh()->short_code)->toBe('BENUE');

    Campaign::factory()->forClient($org)->create();

    $this->actingAs($admin)
        ->put("/admin/clients/{$org->id}", ['name' => 'New name', 'short_code' => 'BNS'])
        ->assertSessionHasErrors('short_code');

    // The name may still change; the code may not.
    $this->actingAs($admin)
        ->put("/admin/clients/{$org->id}", ['name' => 'New name', 'short_code' => 'BENUE'])
        ->assertSessionHasNoErrors();

    expect($org->refresh()->name)->toBe('New name')
        ->and($org->short_code)->toBe('BENUE');
});

it('adds a login with a generated password shown once, which then signs in', function () {
    $admin = person(Role::Admin);
    $org = ClientOrganisation::factory()->create();

    $response = $this->actingAs($admin)
        ->post("/admin/clients/{$org->id}/users", ['name' => 'Ada Okafor', 'email' => 'Ada@Client.ng'])
        ->assertRedirect();

    $flash = (string) $response->getSession()?->get('status');
    preg_match('/First password: (\S+)/', $flash, $m);
    $password = $m[1] ?? '';

    $user = ClientUser::query()->where('email', 'ada@client.ng')->firstOrFail();

    expect(strlen($password))->toBe(14)
        ->and(Hash::check($password, $user->password))->toBeTrue()
        ->and($user->client_organisation_id)->toBe($org->id);

    // The same address twice is refused.
    $this->actingAs($admin)
        ->post("/admin/clients/{$org->id}/users", ['name' => 'Again', 'email' => 'ada@client.ng'])
        ->assertSessionHasErrors('email');
});

it('suspends rather than deletes, and a suspension reaches the door', function () {
    $admin = person(Role::Admin);
    $org = ClientOrganisation::factory()->create();
    $user = ClientUser::factory()->create(['client_organisation_id' => $org->id]);

    expect($user->canSignIn())->toBeTrue();

    $this->actingAs($admin)->post("/admin/client-users/{$user->id}/status", ['status' => 'suspended'])->assertRedirect();
    expect($user->refresh()->canSignIn())->toBeFalse();

    $this->actingAs($admin)->post("/admin/client-users/{$user->id}/status", ['status' => 'active'])->assertRedirect();
    $this->actingAs($admin)->post("/admin/clients/{$org->id}/status", ['status' => 'suspended'])->assertRedirect();

    // A live login inside a suspended organisation is still refused.
    expect($user->refresh()->canSignIn())->toBeFalse()
        ->and(ClientOrganisation::query()->whereKey($org->id)->exists())->toBeTrue()
        ->and(ClientUser::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('resets a password to a new generated one and records who did it', function () {
    $admin = person(Role::Admin);
    $user = ClientUser::factory()->create();
    $old = $user->password;

    $this->actingAs($admin)->post("/admin/client-users/{$user->id}/reset-password")->assertRedirect();

    expect($user->refresh()->password)->not->toBe($old)
        ->and(VerificationEvent::query()->where('event', 'client_user.password_reset')->where('actor_id', $admin->id)->exists())->toBeTrue();
});
