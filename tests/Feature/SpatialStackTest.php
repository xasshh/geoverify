<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * The whole register rests on PostGIS and H3 being present and correct. If these
 * fail, nothing downstream is worth running, so they are asserted directly rather
 * than assumed by the migrations that use them.
 */
it('has every extension the register depends on', function (string $extension) {
    $version = DB::scalar(
        'select extversion from pg_extension where extname = ?',
        [$extension],
    );

    expect($version)->toBeString();
})->with(['postgis', 'h3', 'h3_postgis', 'pg_trgm', 'pgcrypto']);

it('generates H3 cells over Abuja Municipal geography', function () {
    $cells = DB::select(
        "select cell::bigint as h3_index
         from h3_polygon_to_cells(
            st_geomfromtext('POLYGON((7.44 9.03, 7.50 9.03, 7.50 9.08, 7.44 9.08, 7.44 9.03))', 4326), 9
         ) as cell",
    );

    // A 0.06 by 0.05 degree box over Abuja holds a few hundred resolution 9 cells.
    expect($cells)->toHaveCount(385)
        ->and($cells[0]->h3_index)->toBeInt();
});

it('round trips an h3index through bigint without loss', function () {
    // structures.h3_index and grid_cells.h3_index are bigint columns, so this
    // conversion has to be exact or the grid silently detaches from its cells.
    $lossless = DB::scalar(
        'select (cell::bigint::h3index = cell) as lossless
         from (select h3_latlng_to_cell(st_setsrid(st_point(7.4951, 9.0587), 4326), 9) as cell) t',
    );

    expect($lossless)->toBeTrue();
});

it('resolves a point into the H3 cell that contains it', function () {
    $containsPoint = DB::scalar(
        'select st_contains(
            h3_cell_to_boundary_geometry(h3_latlng_to_cell(st_setsrid(st_point(7.4951, 9.0587), 4326), 9)),
            st_setsrid(st_point(7.4951, 9.0587), 4326)
         )',
    );

    expect($containsPoint)->toBeTrue();
});

it('produces resolution 9 cells of roughly 0.1 square kilometres', function () {
    $areaM2 = DB::scalar(
        'select st_area(h3_cell_to_boundary_geometry(
            h3_latlng_to_cell(st_setsrid(st_point(7.4951, 9.0587), 4326), 9)
         )::geography)',
    );

    // The brief specifies resolution 9 at about 0.105 km2. Cell area varies with
    // latitude, so this asserts the order of magnitude rather than an exact figure.
    expect((float) $areaM2)->toBeGreaterThan(80_000.0)
        ->and((float) $areaM2)->toBeLessThan(120_000.0);
});
