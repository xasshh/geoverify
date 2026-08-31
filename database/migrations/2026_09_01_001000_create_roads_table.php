<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The street network, so a map has landmarks instead of only outlines.
 *
 * Roads were always in the offline pack's design and never in the database:
 * geoverify:pack-build took a file path and handed it straight to tippecanoe, so
 * the one place roads existed was inside a built pack. Nothing else could read
 * them, which is why the console and the client's coverage map draw boundaries
 * on empty ground.
 *
 * Not scoped to a mandate. A road does not belong to one: Ahmadu Bello Way runs
 * through several and would either be duplicated per mandate or arbitrarily
 * assigned to one of them. Queries clip against the boundary they need instead,
 * which is what PostGIS is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roads', function (Blueprint $table): void {
            $table->id();

            $table->string('source', 32)->default('openstreetmap');

            // The way id from the source. Stable enough to make a re-ingest
            // update rather than duplicate, which is what makes the command
            // resumable and safe to run again after a partial extract.
            $table->string('source_id', 64);

            $table->string('name')->nullable();

            // The road number, where it has one: A2, AKW-134. Signage carries it
            // more prominently than the name on a trunk route, and an officer
            // reading a junction sign is matching the number first.
            $table->string('ref', 32)->nullable();

            // The OSM highway class: trunk, primary, secondary, tertiary,
            // residential, unclassified, track. Kept as the source's own string
            // rather than remapped, so the styling can be changed without a
            // re-ingest and a class we have not thought about still stores.
            $table->string('highway', 32);

            $table->timestamps();

            $table->unique(['source', 'source_id']);
            $table->index('highway');
        });

        DB::statement('ALTER TABLE roads ADD COLUMN geometry geometry(LineString, 4326) NOT NULL');
        DB::statement('CREATE INDEX roads_geometry_gist ON roads USING GIST (geometry)');

        // Read by name on the label queries, which look for the longest run of
        // one name inside a viewport.
        DB::statement('CREATE INDEX roads_name_idx ON roads (name) WHERE name IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('roads');
    }
};
