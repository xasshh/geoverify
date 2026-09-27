<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\GenerateGrid;
use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Actions\AssignCells;
use App\Domain\Field\Actions\FieldMessaging;
use App\Domain\Field\Models\FieldMessage;
use App\Domain\Verification\Actions\ReviewObservation;
use App\Domain\Verification\Enums\ReviewDecision;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The field inbox, and the screens built around it.
 *
 * What matters is who hears what: an officer sees their own thread and nobody
 * else's, a return and an assignment always reach the officer they concern, a
 * broadcast reaches exactly the officers it names, and a message sent twice
 * from a handset with a bad connection is one message.
 */
/**
 * One mandate, a cell each for several officers. assignedCell() makes a fresh
 * mandate per call, and a second mandate over the same ground has no cells,
 * because H3 indexes are unique across the grid.
 *
 * @param  list<array{0: User, 1: User}>  $pairs  Officer and the supervisor assigning them.
 * @return list<GridCell>
 */
function cellsFor(array $pairs): array
{
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);
    $cells = GridCell::query()->where('coverage_area_id', $area->id)->orderBy('id')->limit(count($pairs))->get()->all();

    foreach ($pairs as $i => [$officer, $supervisor]) {
        app(AssignCells::class)->assign([$cells[$i]->id], $officer, $supervisor);
    }

    return $cells;
}

it('tells an officer about a capture sent back, with the reason, in their inbox', function () {
    $supervisor = person(Role::Supervisor, 'Hauwa Ibrahim');
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);
    $observation = captureIn($cell, $officer, sessionFor($officer, $cell), now()->subHour());

    app(ReviewObservation::class)($observation, ReviewDecision::Return, $supervisor, 'GPS was ±28 m. Stand at the main gate.');

    $returned = FieldMessage::query()->where('officer_id', $officer->id)->where('kind', FieldMessage::KIND_RETURNED)->sole();

    expect($returned->body)->toBe('GPS was ±28 m. Stand at the main gate.')
        ->and($returned->observation_id)->toBe($observation->id)
        ->and($returned->sender_id)->toBe($supervisor->id)
        ->and($returned->direction)->toBe(FieldMessage::TO_OFFICER);
});

it('sends nothing when a capture is accepted', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);
    $observation = captureIn($cell, $officer, sessionFor($officer, $cell), now()->subHour());

    app(ReviewObservation::class)($observation, ReviewDecision::Accept, $supervisor);

    expect(FieldMessage::query()->where('kind', FieldMessage::KIND_RETURNED)->count())->toBe(0);
});

it('tells an officer which cells were handed to them, in one message', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);

    assignedCell($officer, $supervisor);

    $message = FieldMessage::query()->where('officer_id', $officer->id)->where('kind', FieldMessage::KIND_CELL_ASSIGNED)->sole();

    expect($message->body)->toContain('is now yours');
});

it('keeps a message sent twice from the handset as one message', function () {
    $officer = person(Role::Officer);
    $uuid = (string) Str::uuid7();

    $first = app(FieldMessaging::class)->fromOfficer($officer, $uuid, 'On my way');
    $again = app(FieldMessaging::class)->fromOfficer($officer, $uuid, 'On my way');

    expect($again->id)->toBe($first->id)
        ->and(FieldMessage::query()->count())->toBe(1);

    // Another officer reusing the uuid is a collision, not a replay, and is
    // never answered with somebody else's message.
    expect(fn () => app(FieldMessaging::class)->fromOfficer(person(Role::Officer), $uuid, 'Hello'))
        ->toThrow(RuntimeException::class, 'already taken');
});

it('keeps the time a message was written when it arrives late', function () {
    $officer = person(Role::Officer);
    $written = now()->subHours(3)->startOfSecond();

    $message = app(FieldMessaging::class)->fromOfficer($officer, (string) Str::uuid7(), 'Site closed', $written);

    expect($message->sent_at->equalTo($written))->toBeTrue();
});

it('broadcasts to the whole team, chosen officers or a set of cells, and nobody else', function () {
    $supervisor = person(Role::Supervisor);
    $a = person(Role::Officer, 'Aisha');
    $b = person(Role::Officer, 'Bala');
    $outsider = person(Role::Officer, 'Not on the team');

    [$cellA] = cellsFor([[$a, $supervisor], [$b, $supervisor], [$outsider, person(Role::Supervisor)]]);

    $messaging = app(FieldMessaging::class);

    expect($messaging->broadcast($supervisor, 'team', 'Rain after 2 pm.', pin: true))->toBe(2)
        ->and(FieldMessage::query()->where('kind', FieldMessage::KIND_BROADCAST)->where('officer_id', $outsider->id)->exists())->toBeFalse()
        ->and(FieldMessage::query()->where('kind', FieldMessage::KIND_BROADCAST)->whereNotNull('pinned_at')->count())->toBe(2);

    expect($messaging->broadcast($supervisor, 'selected', 'Call me.', [$b->id, $outsider->id]))->toBe(1);
    expect($messaging->broadcast($supervisor, 'cells', 'Your cell floods.', [], [$cellA->h3()]))->toBe(1)
        ->and(FieldMessage::query()->where('body', 'Your cell floods.')->sole()->officer_id)->toBe($a->id);

    expect(fn () => $messaging->broadcast($a, 'team', 'Hi all'))->toThrow(RuntimeException::class, 'Only a supervisor');
});

it('lets an officer read and write only their own thread', function () {
    $supervisor = person(Role::Supervisor);
    $mine = person(Role::Officer);
    $theirs = person(Role::Officer);
    cellsFor([[$mine, $supervisor], [$theirs, $supervisor]]);

    app(FieldMessaging::class)->toOfficer($supervisor, $theirs, 'Private to them');

    $this->actingAs($mine)
        ->getJson('/api/field/messages')
        ->assertOk()
        ->assertJsonMissing(['body' => 'Private to them']);

    $uuid = (string) Str::uuid7();

    $this->actingAs($mine)
        ->postJson('/api/field/messages', ['client_uuid' => $uuid, 'body' => 'On my way'])
        ->assertOk()
        ->assertJsonPath('message.direction', 'from_officer');

    expect(FieldMessage::query()->where('client_uuid', $uuid)->sole()->officer_id)->toBe($mine->id);
});

it('marks only what was sent to the reader as read', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    assignedCell($officer, $supervisor);

    $down = app(FieldMessaging::class)->toOfficer($supervisor, $officer, 'Finish by 4 pm');
    $up = app(FieldMessaging::class)->fromOfficer($officer, (string) Str::uuid7(), 'On it');

    $this->actingAs($officer)->postJson('/api/field/messages/read', ['up_to' => $up->id])->assertOk();

    expect($down->refresh()->read_at)->not->toBeNull()
        ->and($up->refresh()->read_at)->toBeNull();
});

it('opens the officer on Today, with their day counted from the register', function () {
    $supervisor = person(Role::Supervisor, 'Hauwa Ibrahim');
    $officer = person(Role::Officer, 'Musa Danjuma');
    $cell = assignedCell($officer, $supervisor);
    captureIn($cell, $officer, sessionFor($officer, $cell), now()->subMinutes(30));

    $this->actingAs($officer)
        ->get('/field')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('field/Today')
            ->where('day.capturesToday', 1)
            ->where('day.supervisor.name', 'Hauwa Ibrahim')
            ->where('day.cells.total', 1)
            ->has('day.captures', 1));

    foreach (['/field/records', '/field/inbox', '/field/brief', '/field/device'] as $page) {
        $this->actingAs($officer)->get($page)->assertOk();
    }
});

it('opens a supervisor on Team today with their team and the review queue', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer, 'Musa Danjuma');
    $cell = assignedCell($officer, $supervisor);
    captureIn($cell, $officer, sessionFor($officer, $cell), now()->subMinutes(30));

    $this->actingAs($supervisor)
        ->get('/console')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('console/TeamToday')
            ->has('officers', 1)
            ->where('officers.0.name', 'Musa Danjuma')
            ->where('stats.capturesToday', 1)
            ->where('stats.awaitingQa', 1));

    $this->actingAs($supervisor)->get("/console/messages/{$officer->id}")->assertOk();
    $this->actingAs($supervisor)->post('/console/broadcasts', ['audience' => 'team', 'body' => 'Rain after 2 pm.'])->assertSessionHasNoErrors();

    expect(FieldMessage::query()->where('kind', FieldMessage::KIND_BROADCAST)->count())->toBe(1);

    // An officer is not a supervisor, however they ask.
    $this->actingAs($officer)->get('/console')->assertRedirect();
});
