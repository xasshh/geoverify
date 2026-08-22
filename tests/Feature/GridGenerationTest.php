<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\GenerateGrid;
use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Coverage\Models\GridCell;
use Illuminate\Support\Facades\DB;

/**
 * A small square inside Abuja Municipal. Real coordinates, small enough that the
 * suite stays fast.
 */
function testMandate(string $wkt = 'POLYGON((7.44 9.03, 7.50 9.03, 7.50 9.08, 7.44 9.08, 7.44 9.03))'): CoverageArea
{
    $id = DB::scalar(
        "INSERT INTO coverage_areas
            (client_name, name, status, accuracy_threshold_m, default_h3_resolution, boundary, created_at, updated_at)
         VALUES ('Test client', 'Test mandate', 'active', 15, 9,
                 ST_Multi(ST_GeomFromText(?, 4326)), now(), now())
         RETURNING id",
        [$wkt],
    );

    return CoverageArea::query()->findOrFail($id);
}

it('tiles a mandate into H3 cells', function () {
    $area = testMandate();

    $result = app(GenerateGrid::class)->generate($area, 9);

    expect($result['created'])->toBeGreaterThan(300)
        ->and($result['total'])->toBe($result['created'])
        ->and(GridCell::query()->where('coverage_area_id', $area->id)->count())->toBe($result['total']);
});

it('is idempotent, so an interrupted run is repaired by running it again', function () {
    $area = testMandate();
    $generator = app(GenerateGrid::class);

    $first = $generator->generate($area, 9);
    $second = $generator->generate($area, 9);

    expect($second['created'])->toBe(0)
        ->and($second['existing'])->toBe($first['total'])
        ->and($second['total'])->toBe($first['total']);
});

it('covers the mandate edge, so no building at the boundary is left out of the grid', function () {
    $area = testMandate();
    $generator = app(GenerateGrid::class);

    $generator->generate($area, 9, GenerateGrid::CONTAINMENT_OVERLAPPING);
    $overlapping = GridCell::query()->where('coverage_area_id', $area->id)->count();

    // Centre containment drops the edge cells. That difference is exactly the
    // ground that would go unenumerated, so it must not be zero and overlapping
    // must be the larger of the two.
    $centreCount = (int) DB::scalar(
        'select count(*) from (
            select h3_polygon_to_cells_experimental(boundary, 9, ?) from coverage_areas where id = ?
         ) t',
        [GenerateGrid::CONTAINMENT_CENTER, $area->id],
    );

    expect($overlapping)->toBeGreaterThan($centreCount);
});

it('leaves no cell outside the mandate it belongs to', function () {
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);

    $stray = DB::scalar(
        'select count(*) from grid_cells g
          join coverage_areas c on c.id = g.coverage_area_id
         where g.coverage_area_id = ? and not ST_Intersects(g.boundary, c.boundary)',
        [$area->id],
    );

    expect((int) $stray)->toBe(0);
});

it('records a parent one resolution up for every cell', function () {
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);

    $withoutParent = GridCell::query()
        ->where('coverage_area_id', $area->id)
        ->whereNull('parent_h3_index')
        ->count();

    // H3 nests roughly seven children per parent, so a correct grid has far fewer
    // distinct parents than cells but a parent on every one.
    $cells = GridCell::query()->where('coverage_area_id', $area->id)->count();
    $parents = GridCell::query()->where('coverage_area_id', $area->id)
        ->distinct()->count('parent_h3_index');

    expect($withoutParent)->toBe(0)
        ->and($parents)->toBeLessThan($cells)
        ->and($parents * 8)->toBeGreaterThan($cells);
});

it('refuses a resolution H3 does not have', function () {
    $area = testMandate();

    expect(fn () => app(GenerateGrid::class)->generate($area, 16))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a containment mode it does not implement', function () {
    $area = testMandate();

    expect(fn () => app(GenerateGrid::class)->generate($area, 9, 'sideways'))
        ->toThrow(InvalidArgumentException::class);
});

it('reports cells already held by another mandate rather than silently omitting them', function () {
    $first = testMandate();
    app(GenerateGrid::class)->generate($first, 9);

    // A second mandate over the same ground. A cell belongs to exactly one, so the
    // second grid has holes, and the officer working it must not discover that in
    // the field.
    $second = testMandate();
    $result = app(GenerateGrid::class)->generate($second, 9);

    expect($result['created'])->toBe(0)
        ->and($result['claimed_elsewhere'])->toBeGreaterThan(300);
});
