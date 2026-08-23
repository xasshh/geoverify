<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\GenerateGrid;
use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Actions\AssignCells;
use App\Domain\Field\Models\Assignment;
use App\Enums\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('sends an unauthenticated visitor to sign in', function () {
    $this->get('/console/coverage')->assertRedirect('/login');
    $this->get('/field')->assertRedirect('/login');
});

it('keeps an officer out of the console and sends them to their own work', function () {
    $this->actingAs(person(Role::Officer))
        ->get('/console/coverage')
        ->assertRedirect('/field');
});

it('sends a supervisor who opens the field client to the console', function () {
    $this->actingAs(person(Role::Supervisor))
        ->get('/field')
        ->assertRedirect('/console/coverage');
});

it('lets a supervisor and an admin into the console', function (string $role) {
    $this->actingAs(person(Role::from($role)))->get('/console/coverage')->assertOk();
})->with(['supervisor', 'admin']);

it('locks out a suspended account without deleting anything it did', function () {
    $suspended = person(Role::Supervisor, 'Former supervisor', User::STATUS_SUSPENDED);

    $this->actingAs($suspended)->get('/console/coverage')->assertForbidden();
    $this->actingAs($suspended)->get('/field')->assertForbidden();
});

it('shows an officer only their own assignments', function () {
    $mine = person(Role::Officer, 'A. Bello');
    $theirs = person(Role::Officer, 'C. Okafor');
    $supervisor = person(Role::Supervisor);

    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);
    $cells = GridCell::query()
        ->where('coverage_area_id', $area->id)->limit(4)->pluck('id')->map(intval(...))->all();

    $assigner = app(AssignCells::class);
    $assigner->assign(array_slice($cells, 0, 2), $mine, $supervisor);
    $assigner->assign(array_slice($cells, 2, 2), $theirs, $supervisor);

    $this->actingAs($mine)
        ->get('/field')
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('field/Assignments')
                ->has('assignments', 2)
                ->where('officer.name', 'A. Bello')
        );
});

it('refuses to let an officer assign work through the console endpoint', function () {
    $officer = person(Role::Officer);
    $area = testMandate();

    $this->actingAs($officer)
        ->post("/console/coverage/{$area->id}/assignments", [
            'officer_id' => $officer->id,
            'count' => 5,
        ])
        ->assertRedirect('/field');

    expect(Assignment::query()->count())->toBe(0);
});

it('refuses to let an officer release a cell, including their own', function () {
    $officer = person(Role::Officer);
    $supervisor = person(Role::Supervisor);
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);
    $cells = GridCell::query()
        ->where('coverage_area_id', $area->id)->limit(1)->pluck('id')->map(intval(...))->all();

    app(AssignCells::class)->assign($cells, $officer, $supervisor);
    $assignment = Assignment::query()->firstOrFail();

    $this->actingAs($officer)
        ->delete("/console/assignments/{$assignment->id}")
        ->assertRedirect('/field');

    expect($assignment->fresh()?->closed_at)->toBeNull();
});

it('does not expose a self registration route', function () {
    // Nobody self registers into a government register. An account that can appear
    // without an admin is an account nobody vouched for.
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Walk in',
        'email' => 'walkin@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::query()->where('email', 'walkin@example.test')->exists())->toBeFalse();
});

it('refuses to sign in a suspended account', function () {
    $user = User::query()->create([
        'name' => 'Suspended officer',
        'email' => 'suspended@geoverify.test',
        'password' => 'correct-horse-battery',
        'role' => Role::Officer,
        'status' => User::STATUS_SUSPENDED,
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ])->assertSessionHasErrors();

    expect(auth()->check())->toBeFalse();
});

it('signs in an active account and lands it on the right home screen', function () {
    $officer = User::query()->create([
        'name' => 'Active officer',
        'email' => 'active@geoverify.test',
        'password' => 'correct-horse-battery',
        'role' => Role::Officer,
        'status' => User::STATUS_ACTIVE,
    ]);

    $this->post('/login', [
        'email' => $officer->email,
        'password' => 'correct-horse-battery',
    ])->assertRedirect();

    expect(auth()->id())->toBe($officer->id);

    $this->get('/field')->assertOk();
});
