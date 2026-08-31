<?php

declare(strict_types=1);

use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Field\Actions\RegisterDevice;
use App\Domain\Field\Models\Device;
use App\Domain\Registry\Actions\CaptureStructure;
use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Staff\Actions\CreateStaffMember;
use App\Domain\Staff\Actions\SetStaffStatus;
use App\Domain\Verification\Actions\BuildEscalationQueue;
use App\Domain\Verification\Actions\ResolveEscalation;
use App\Domain\Verification\Actions\ReviewObservation;
use App\Domain\Verification\Enums\ReviewDecision;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The in-house views.
 *
 * The access tests matter most. Role::supervises() is true for an admin, so the
 * console and the admin section would collapse into one surface the moment
 * anything reached for the wrong check, and escalation exists precisely to keep
 * two people in the loop.
 */

/** A capture a supervisor has escalated, ready for an admin to rule on. */
function escalatedCapture(User $officer, User $supervisor): StructureObservation
{
    $cell = assignedCell($officer, $supervisor);

    $structure = app(CaptureStructure::class)->capture(captureFor($cell), $officer);

    $observation = $structure->observations()->latest('observed_at')->firstOrFail();

    app(ReviewObservation::class)(
        $observation,
        ReviewDecision::Escalate,
        $supervisor,
        'The trace is a straight line and the photograph shows a different building.',
    );

    return $observation->refresh();
}

it('keeps every in-house view away from supervisors and officers', function (string $path) {
    $officer = person(Role::Officer);
    $supervisor = person(Role::Supervisor);

    // A supervisor is refused rather than redirected: they went looking.
    $this->actingAs($supervisor)->get($path)->assertForbidden();
    $this->actingAs($officer)->get($path)->assertForbidden();
})->with([
    '/admin/escalations',
    '/admin/audit',
    '/admin/people',
    '/admin/mandates',
]);

it('lets an admin in', function (string $path) {
    $admin = person(Role::Admin);

    $this->actingAs($admin)->get($path)->assertOk();
})->with([
    '/admin/escalations',
    '/admin/audit',
    '/admin/people',
    '/admin/mandates',
]);

it('shows an escalated capture with the reason the supervisor gave', function () {
    $officer = person(Role::Officer);
    $supervisor = person(Role::Supervisor);

    escalatedCapture($officer, $supervisor);

    $queue = app(BuildEscalationQueue::class)();

    expect($queue)->toHaveCount(1)
        ->and($queue[0]['reason'])->toContain('straight line')
        ->and($queue[0]['escalatedBy'])->toBe($supervisor->name)
        ->and($queue[0]['officer']['name'])->toBe($officer->name);
});

it('upholds an escalation by sending the capture back to the officer', function () {
    $officer = person(Role::Officer);
    $supervisor = person(Role::Supervisor);
    $admin = person(Role::Admin);

    $observation = escalatedCapture($officer, $supervisor);

    app(ResolveEscalation::class)(
        $observation,
        $admin,
        ResolveEscalation::UPHELD,
        'The photographs are of a different street.',
    );

    expect($observation->refresh()->status)->toBe(Structure::STATUS_REJECTED);

    // Both facts are on the record: the capture was returned, and separately an
    // escalation about a person was ruled on.
    $events = VerificationEvent::query()
        ->where('subject_id', $observation->id)
        ->pluck('event');

    expect($events)->toContain('observation.escalated')
        ->and($events)->toContain('observation.returned')
        ->and($events)->toContain('observation.escalation_resolved');
});

it('dismisses an escalation by entering the capture into the register', function () {
    $officer = person(Role::Officer);
    $supervisor = person(Role::Supervisor);
    $admin = person(Role::Admin);

    $observation = escalatedCapture($officer, $supervisor);

    app(ResolveEscalation::class)(
        $observation,
        $admin,
        ResolveEscalation::DISMISSED,
        'The trace is thin because the shop is on a short street. Nothing wrong here.',
    );

    expect($observation->refresh()->status)->toBe(Structure::STATUS_ACCEPTED)
        ->and(app(BuildEscalationQueue::class)())->toBeEmpty();
});

it('will not let the person who raised an escalation rule on it', function () {
    $officer = person(Role::Officer);

    // One person holding both roles is the case this guards. An admin
    // supervises too, so without the check they could escalate and then rule.
    $admin = person(Role::Admin);
    $observation = escalatedCapture($officer, $admin);

    expect(fn () => app(ResolveEscalation::class)(
        $observation,
        $admin,
        ResolveEscalation::UPHELD,
        'I still think this is fabricated.',
    ))->toThrow(RuntimeException::class, 'somebody else has to rule on it');
});

it('refuses to resolve a capture that is not escalated', function () {
    $officer = person(Role::Officer);
    $supervisor = person(Role::Supervisor);
    $admin = person(Role::Admin);

    $cell = assignedCell($officer, $supervisor);
    $structure = app(CaptureStructure::class)->capture(captureFor($cell), $officer);
    $observation = $structure->observations()->latest('observed_at')->firstOrFail();

    expect(fn () => app(ResolveEscalation::class)(
        $observation,
        $admin,
        ResolveEscalation::UPHELD,
        'Nothing has been raised about this.',
    ))->toThrow(RuntimeException::class, 'not escalated');
});

it('adds a staff member with a generated password and a reference in series', function () {
    $admin = person(Role::Admin);

    $result = app(CreateStaffMember::class)(
        $admin,
        'Ifeoma Nwachukwu',
        'Ifeoma@GeoVerify.test',
        Role::Officer,
    );

    expect($result['user']->email)->toBe('ifeoma@geoverify.test')
        ->and($result['user']->staff_ref)->toStartWith('FO-')
        ->and($result['password'])->toHaveLength(14)
        // Created, not registered. The password is never one the admin chose.
        ->and($result['user']->status)->toBe(User::STATUS_ACTIVE);

    expect(VerificationEvent::query()->where('event', 'staff.created')->exists())->toBeTrue();
});

it('refuses a second staff member on the same address', function () {
    $admin = person(Role::Admin);
    $create = app(CreateStaffMember::class);

    $create($admin, 'First', 'shared@geoverify.test', Role::Officer);

    expect(fn () => $create($admin, 'Second', 'shared@geoverify.test', Role::Supervisor))
        ->toThrow(RuntimeException::class, 'already works here');
});

it('will not let a supervisor add staff', function () {
    $supervisor = person(Role::Supervisor);

    expect(fn () => app(CreateStaffMember::class)(
        $supervisor,
        'Someone',
        'someone@geoverify.test',
        Role::Officer,
    ))->toThrow(RuntimeException::class, 'Only an administrator');
});

it('suspends a person, cuts their tokens, and keeps their captures', function () {
    $officer = person(Role::Officer);
    $supervisor = person(Role::Supervisor);
    $admin = person(Role::Admin);

    $cell = assignedCell($officer, $supervisor);
    app(CaptureStructure::class)->capture(captureFor($cell), $officer);
    app(RegisterDevice::class)->register($officer, 'handset-suspend');

    expect($officer->tokens()->count())->toBe(1);

    app(SetStaffStatus::class)(
        $admin,
        $officer,
        User::STATUS_SUSPENDED,
        'Left the programme on 30 August.',
    );

    expect($officer->refresh()->status)->toBe(User::STATUS_SUSPENDED)
        ->and($officer->tokens()->count())->toBe(0)
        // The person survives, and so does everything they recorded.
        ->and(User::query()->whereKey($officer->id)->exists())->toBeTrue()
        ->and(DB::scalar('select count(*) from structure_observations where captured_by = ?', [$officer->id]))->toBe(1);
});

it('will not let an admin suspend themselves', function () {
    $admin = person(Role::Admin);

    expect(fn () => app(SetStaffStatus::class)(
        $admin,
        $admin,
        User::STATUS_SUSPENDED,
        'Locking myself out by accident.',
    ))->toThrow(RuntimeException::class, 'cannot suspend yourself');
});

it('writes a revocation to the log, because a status change is never quiet', function () {
    $officer = person(Role::Officer);
    $admin = person(Role::Admin);

    $device = app(RegisterDevice::class)->register($officer, 'handset-lost')['device'];

    $this->actingAs($admin)
        ->post("/admin/devices/{$device->id}/revoke", ['reason' => 'Reported lost at Wuse market'])
        ->assertRedirect();

    expect($device->refresh()->status)->toBe(Device::STATUS_REVOKED)
        ->and(VerificationEvent::query()->where('event', 'device.revoked')->exists())->toBeTrue();
});

it('creates a mandate and tiles it in one step', function () {
    $admin = person(Role::Admin);

    // The same small square the grid tests use, loaded as an LGA boundary so
    // the screen has something real to contract over.
    DB::statement(
        "insert into admin_boundaries (level, code, name, source, boundary, created_at, updated_at)
         values ('lga', 'NG-TEST-01', 'Test LGA', 'test',
                 ST_Multi(ST_GeomFromText('POLYGON((7.44 9.03, 7.50 9.03, 7.50 9.08, 7.44 9.08, 7.44 9.03))', 4326)),
                 now(), now())"
    );

    $this->actingAs($admin)->post('/admin/mandates', [
        'lgaCode' => 'NG-TEST-01',
        'client' => 'Federal Ministry of Trade',
        'resolution' => 9,
    ])->assertRedirect();

    $created = CoverageArea::query()
        ->where('client_name', 'Federal Ministry of Trade')
        ->firstOrFail();

    // Created and tiled together. A mandate with no work list is not a mandate,
    // and leaving the two apart is how one gets created and forgotten.
    expect(DB::scalar('select count(*) from grid_cells where coverage_area_id = ?', [$created->id]))
        ->toBeGreaterThan(300)
        ->and($created->lga_code)->toBe('NG-TEST-01');

    expect(VerificationEvent::query()->where('event', 'mandate.created')->exists())->toBeTrue();
});

it('filters the audit log rather than searching it', function () {
    $officer = person(Role::Officer);
    $supervisor = person(Role::Supervisor);
    $admin = person(Role::Admin);

    escalatedCapture($officer, $supervisor);

    $this->actingAs($admin)
        ->get('/admin/audit?event=observation.escalated')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('events.0.event', 'observation.escalated')
            ->where('filters.event', 'observation.escalated'));
});
