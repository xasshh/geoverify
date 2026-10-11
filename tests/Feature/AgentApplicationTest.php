<?php

declare(strict_types=1);

use App\Domain\Identity\Models\IdentityClaim;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Staff\Models\AgentApplication;
use App\Domain\Staff\Models\AgentApplicationFile;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/**
 * Applying to become a field agent (onboarding data of 2026-10-07).
 *
 * What matters: an applicant is a portal account and never staff until an
 * administrator approves them; the NIN is kept as a hash and four digits; the
 * documents are encrypted on disk and opened only by an administrator; and a
 * sent application cannot be changed unless it is sent back.
 */
beforeEach(function () {
    Storage::fake('local');

    $stateId = (int) DB::scalar("insert into admin_boundaries (level, code, name, source, boundary, created_at, updated_at) values ('state', 'NG-T1', 'Benue', 'test', ST_Multi(ST_GeomFromText('POLYGON((8.4 7.6, 8.7 7.6, 8.7 7.9, 8.4 7.9, 8.4 7.6))', 4326)), now(), now()) returning id");
    DB::statement("insert into admin_boundaries (level, code, name, source, parent_id, boundary, created_at, updated_at) values ('lga', 'NG-T1-01', 'Makurdi', 'test', ?, ST_Multi(ST_GeomFromText('POLYGON((8.4 7.6, 8.7 7.6, 8.7 7.9, 8.4 7.9, 8.4 7.6))', 4326)), now(), now()) ", [$stateId]);

    $applicant = PortalAccount::query()->create(['name' => 'Ngozi', 'email' => 'ngozi@example.ng', 'password' => 'a-long-password', 'status' => 'active']);
    $applicant->forceFill(['email_verified_at' => now()])->save();
});

function applicant(): PortalAccount
{
    return PortalAccount::query()->where('email', 'ngozi@example.ng')->firstOrFail();
}

function benueState(): int
{
    return (int) DB::table('admin_boundaries')->where('code', 'NG-T1')->value('id');
}

function makurdi(): int
{
    return (int) DB::table('admin_boundaries')->where('code', 'NG-T1-01')->value('id');
}

/** Fills in every part, as the applicant. */
function completeApplication(object $test): void
{
    $test->actingAs(applicant(), 'portal');
    $test->post('/enumerate/agent/start')->assertRedirect('/enumerate/agent');

    $test->post('/enumerate/agent/about', [
        'surname' => 'Okafor', 'first_name' => 'Ngozi', 'date_of_birth' => '1995-04-02', 'sex' => 'female',
        'phone' => '0803 123 4567', 'residential_address' => '12 Ankpa Road, Makurdi',
        'residence_state_id' => benueState(), 'residence_lga_id' => makurdi(), 'languages' => ['English', 'Tiv'],
    ])->assertSessionHasNoErrors();

    $test->post('/enumerate/agent/background', [
        'nin' => '12345678901', 'qualification' => 'ond', 'experience' => ['npc'], 'experience_years' => 2,
        'guarantor' => ['name' => 'Emeka Obi', 'phone' => '0805 000 1111', 'address' => 'Wurukum, Makurdi', 'occupation' => 'Teacher'],
        'next_of_kin' => ['name' => 'Ada Okafor', 'relationship' => 'Sister', 'phone' => '0806 222 3333'],
    ])->assertSessionHasNoErrors();

    foreach (['passport_photo', 'government_id', 'qualification_certificate', 'guarantor_id', 'guarantor_form'] as $kind) {
        $test->post('/enumerate/agent/files', ['kind' => $kind, 'file' => UploadedFile::fake()->image("{$kind}.jpg")])->assertSessionHasNoErrors();
    }

    $test->post('/enumerate/agent/device', ['ownership' => 'personal', 'power_bank' => true, 'gps_best_accuracy_m' => 6.5, 'gps_samples' => 40])->assertSessionHasNoErrors();

    $test->post('/enumerate/agent/agree', ['agreed' => array_keys(AgentApplication::CONSENTS), 'signed_name' => 'Ngozi Okafor'])->assertSessionHasNoErrors();
}

it('opens from the Enumerate dashboard for a signed in person', function () {
    $this->actingAs(applicant(), 'portal')
        ->get('/enumerate/agent')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('enumerate/AgentApplication')->where('application', null));
});

it('sends somebody signed out to the Enumerate sign in', function () {
    $this->get('/enumerate/agent')->assertRedirect('/enumerate/sign-in');
});

it('keeps the NIN as a hash and four digits, never the number', function () {
    completeApplication($this);

    $claim = IdentityClaim::query()->where('claimable_type', (new AgentApplication)->getMorphClass())->firstOrFail();
    expect($claim->kind)->toBe('nin')
        ->and($claim->reference_last4)->toBe('8901')
        ->and($claim->reference_token)->not->toContain('12345678901');

    // Nowhere in the application row or the audit trail either.
    $row = json_encode(DB::table('agent_applications')->first());
    $events = json_encode(DB::table('verification_events')->get());
    expect($row)->not->toContain('12345678901')->and($events)->not->toContain('12345678901');
});

it('encrypts the guarantor and the documents at rest', function () {
    completeApplication($this);

    $raw = DB::table('agent_applications')->value('guarantor');
    expect($raw)->not->toContain('Emeka');

    $file = AgentApplicationFile::query()->where('kind', 'government_id')->firstOrFail();
    $stored = Storage::disk('local')->get($file->path);
    expect($stored)->not->toBeNull()->and(str_starts_with((string) $stored, "\xFF\xD8"))->toBeFalse();
});

it('refuses to send an unfinished application, and sends a finished one', function () {
    $this->actingAs(applicant(), 'portal')->post('/enumerate/agent/start');
    $this->post('/enumerate/agent/submit')->assertSessionHasErrors('submit');

    completeApplication($this);
    $this->post('/enumerate/agent/submit')->assertSessionHasNoErrors();

    $application = AgentApplication::query()->firstOrFail();
    expect($application->status)->toBe(AgentApplication::SUBMITTED);

    // And it cannot be changed now.
    $this->post('/enumerate/agent/device', ['ownership' => 'company', 'power_bank' => false, 'gps_best_accuracy_m' => 50])
        ->assertSessionHasErrors('device');
});

it('refuses an applicant under eighteen', function () {
    $this->actingAs(applicant(), 'portal')->post('/enumerate/agent/start');
    $this->post('/enumerate/agent/about', [
        'surname' => 'Okafor', 'first_name' => 'Ngozi', 'date_of_birth' => now()->subYears(16)->toDateString(), 'sex' => 'female',
        'phone' => '08031234567', 'residential_address' => 'Makurdi',
        'residence_state_id' => benueState(), 'residence_lga_id' => makurdi(), 'languages' => ['English'],
    ])->assertSessionHasErrors('date_of_birth');
});

it('creates no staff account until an administrator approves', function () {
    completeApplication($this);
    $this->post('/enumerate/agent/submit');

    expect(User::query()->where('email', 'ngozi@example.ng')->exists())->toBeFalse();

    $application = AgentApplication::query()->firstOrFail();
    $admin = person(Role::Admin);

    $this->actingAs($admin)
        ->post("/admin/agent-applications/{$application->id}/decide", ['decision' => 'approve', 'role' => 'officer', 'capture_modes' => ['buildings']])
        ->assertRedirect('/admin/agent-applications')
        ->assertSessionHas('status', fn (string $s): bool => str_contains($s, 'First password'));

    $officer = User::query()->where('email', 'ngozi@example.ng')->firstOrFail();
    expect($officer->role)->toBe(Role::Officer)
        ->and($officer->name)->toBe('Ngozi Okafor')
        ->and($application->fresh()?->user_id)->toBe($officer->id)
        ->and($application->fresh()?->status)->toBe(AgentApplication::APPROVED);
});

it('keeps a supervisor out of the applications and their documents', function () {
    completeApplication($this);
    $application = AgentApplication::query()->firstOrFail();
    $file = $application->files()->firstOrFail();

    $supervisor = person(Role::Supervisor);
    $this->actingAs($supervisor)->get('/admin/agent-applications')->assertForbidden();
    $this->actingAs($supervisor)->get("/admin/agent-applications/{$application->id}/files/{$file->id}")->assertForbidden();
});

it('lets an administrator open a document, and records that they did', function () {
    completeApplication($this);
    $application = AgentApplication::query()->firstOrFail();
    $file = $application->files()->where('kind', 'passport_photo')->firstOrFail();

    $this->actingAs(person(Role::Admin))
        ->get("/admin/agent-applications/{$application->id}/files/{$file->id}")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(VerificationEvent::query()->where('event', 'agent_application.document_opened')->exists())->toBeTrue();
});

it('needs a reason to send back, and lets the applicant change and resend', function () {
    completeApplication($this);
    $this->post('/enumerate/agent/submit');
    $application = AgentApplication::query()->firstOrFail();
    $admin = person(Role::Admin);

    $this->actingAs($admin)->post("/admin/agent-applications/{$application->id}/decide", ['decision' => 'send_back', 'note' => 'short'])
        ->assertSessionHasErrors('decision');

    $this->actingAs($admin)->post("/admin/agent-applications/{$application->id}/decide", ['decision' => 'send_back', 'note' => 'The ID photo is blurred. Please take it again.'])
        ->assertSessionHasNoErrors();

    $this->actingAs(applicant(), 'portal')
        ->post('/enumerate/agent/files', ['kind' => 'government_id', 'file' => UploadedFile::fake()->image('id-again.jpg')])
        ->assertSessionHasNoErrors();

    // The first photo is kept, marked replaced, never removed.
    expect(AgentApplicationFile::query()->where('kind', 'government_id')->count())->toBe(2)
        ->and(AgentApplicationFile::query()->where('kind', 'government_id')->whereNull('replaced_at')->count())->toBe(1);

    $this->post('/enumerate/agent/submit')->assertSessionHasNoErrors();
    expect($application->fresh()?->status)->toBe(AgentApplication::SUBMITTED);
});
