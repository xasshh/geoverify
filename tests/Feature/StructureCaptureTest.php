<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\GenerateGrid;
use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Actions\AssignCells;
use App\Domain\Registry\Actions\CaptureStructure;
use App\Domain\Registry\Data\StructureCapture;
use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A cell inside the test mandate, assigned to the given officer so captures are
 * permitted the way they are in the field.
 */
function assignedCell(User $officer, User $supervisor): GridCell
{
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);

    $cell = GridCell::query()->where('coverage_area_id', $area->id)->firstOrFail();
    app(AssignCells::class)->assign([$cell->id], $officer, $supervisor);

    return $cell;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function captureFor(GridCell $cell, array $overrides = []): StructureCapture
{
    return StructureCapture::fromArray(array_merge([
        'client_uuid' => (string) Str::uuid7(),
        'observation_uuid' => (string) Str::uuid7(),
        'grid_cell_id' => $cell->id,
        'longitude' => 7.46,
        'latitude' => 9.05,
        'accuracy_m' => 4.2,
        'structure_type' => 'shophouse',
        'occupancy_status' => 'occupied',
        'floors' => 2,
        'unit_count' => 14,
        'observed_at' => now()->toIso8601String(),
    ], $overrides));
}

it('records a structure with geography resolved by the server', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));

    $structure = app(CaptureStructure::class)->capture(captureFor($cell), $officer);

    expect($structure->status)->toBe(Structure::STATUS_SUBMITTED)
        ->and($structure->h3_index)->toBe($cell->h3_index)
        ->and($structure->unit_count)->toBe(14);

    // The centroid is written by PostGIS from the point, not assembled in PHP.
    $hasCentroid = DB::scalar('select centroid is not null from structures where id = ?', [$structure->id]);
    expect($hasCentroid)->toBeTrue();
});

it('never takes ward, LGA or state from the client', function () {
    // The capture object has no field for them at all, which is the point: a
    // value the device can supply is a value the device can get wrong or lie
    // about. This asserts the shape rather than the behaviour, because the
    // absence is the guarantee.
    $properties = array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass(StructureCapture::class))->getProperties(),
    );

    expect($properties)->not->toContain('wardId')
        ->and($properties)->not->toContain('lgaId')
        ->and($properties)->not->toContain('stateId')
        ->and($properties)->not->toContain('ward');
});

it('is idempotent, so a handset retrying three times creates one structure', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $capture = captureFor($cell);
    $capturer = app(CaptureStructure::class);

    $capturer->capture($capture, $officer);
    $capturer->capture($capture, $officer);
    $capturer->capture($capture, $officer);

    expect(Structure::query()->count())->toBe(1)
        ->and(StructureObservation::query()->count())->toBe(1);
});

it('appends a new observation on a revisit and leaves the previous one untouched', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $capturer = app(CaptureStructure::class);

    $first = captureFor($cell, ['unit_count' => 12, 'occupancy_status' => 'occupied']);
    $structure = $capturer->capture($first, $officer);

    // Same structure, new visit: same client_uuid, a new observation uuid.
    $second = captureFor($cell, [
        'client_uuid' => $first->clientUuid,
        'unit_count' => 14,
        'occupancy_status' => 'occupied',
        'observed_at' => now()->addMonths(6)->toIso8601String(),
    ]);
    $capturer->capture($second, $officer);

    $observations = StructureObservation::query()->orderBy('observed_at')->get();

    expect(Structure::query()->count())->toBe(1)
        ->and($observations)->toHaveCount(2)
        // March stays exactly as March was recorded.
        ->and($observations[0]->unit_count)->toBe(12)
        ->and($observations[1]->unit_count)->toBe(14)
        // The projection moves forward to the latest.
        ->and($structure->fresh()?->unit_count)->toBe(14);
});

it('writes an audit event for the capture and for the revisit', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $capturer = app(CaptureStructure::class);

    $first = captureFor($cell);
    $structure = $capturer->capture($first, $officer);
    $capturer->capture(captureFor($cell, ['client_uuid' => $first->clientUuid]), $officer);

    $events = VerificationEvent::query()
        ->where('subject_type', $structure->getMorphClass())
        ->where('subject_id', $structure->id)
        ->pluck('event');

    expect($events)->toContain('structure.captured')
        ->and($events)->toContain('structure.re_observed');
});

it('moves the cell denominator when a structure is captured', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $cell->update(['footprint_count' => 4]);

    app(CaptureStructure::class)->capture(captureFor($cell), $officer);

    $fresh = $cell->fresh();

    expect($fresh?->structures_captured)->toBe(1)
        ->and($fresh?->coverage_pct)->toBe(25.0);
});

it('accepts a kiosk with no footprint, because that is where much of the trade is', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));

    $structure = app(CaptureStructure::class)->capture(
        captureFor($cell, ['structure_type' => 'kiosk', 'unit_count' => 1, 'floors' => null]),
        $officer,
    );

    $footprint = DB::scalar('select footprint is null from structures where id = ?', [$structure->id]);

    expect($structure->structure_type)->toBe('kiosk')
        ->and($footprint)->toBeTrue();
});

it('refuses a capture from anyone but an active field officer', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));

    expect(fn () => app(CaptureStructure::class)->capture(captureFor($cell), person(Role::Supervisor)))
        ->toThrow(RuntimeException::class, 'Only an active field officer');
});
