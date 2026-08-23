<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A building, or a thing a business operates out of that is not a building.
 *
 * Kiosks, shipping containers, umbrella stands and lock up shops are structures
 * with a non building type and a null footprint, so enterprise to structure stays
 * non nullable and the informal economy is counted rather than excluded.
 *
 * This table holds identity and location: what this thing is and where it stands.
 * What it looked like on a given visit lives in structure_observations, because a
 * revisit records a new observation and never overwrites the last one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('structures', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('grid_cell_id')->constrained();
            $table->foreignId('coverage_area_id')->constrained();

            $table->foreignId('external_footprint_id')->nullable()
                ->constrained()->nullOnDelete();

            // Resolved server side by ST_Contains at ingestion. A client supplied
            // ward is never trusted: a device can claim anything.
            $table->foreignId('ward_id')->nullable()->constrained('admin_boundaries');
            $table->foreignId('lga_id')->nullable()->constrained('admin_boundaries');
            $table->foreignId('state_id')->nullable()->constrained('admin_boundaries');

            $table->bigInteger('h3_index');

            // Open Location Code, for reading out over a phone to someone who has
            // to find the place again.
            $table->string('plus_code', 24)->nullable();

            // Set at creation and not revised by later visits: this is where the
            // thing stands, not what it looked like on a given day.
            $table->foreignId('captured_by')->constrained('users');
            $table->timestamp('captured_at');
            $table->decimal('capture_accuracy_m', 8, 2)->nullable();

            // The projection of the latest accepted observation, maintained on
            // write so ordinary reads stay simple.
            $table->string('structure_type', 32);
            $table->string('layout_class', 32)->nullable();
            $table->unsignedSmallInteger('floors')->nullable();
            $table->unsignedSmallInteger('unit_count')->nullable();
            $table->string('condition', 24)->nullable();
            $table->string('occupancy_status', 32)->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->string('status', 24)->default('draft');

            // Client generated UUID v7. A record is addressable before the server
            // has seen it, and a device retrying three times creates one row.
            $table->uuid('client_uuid')->unique();

            $table->timestamps();

            $table->index(['grid_cell_id', 'status']);
            $table->index(['coverage_area_id', 'status']);
            $table->index('h3_index');
            $table->index('ward_id');
        });

        // Null for anything with no detected outline: a kiosk, or a building the
        // sources missed. The centroid is always present.
        DB::statement('ALTER TABLE structures ADD COLUMN footprint geometry(Polygon, 4326)');
        DB::statement('ALTER TABLE structures ADD COLUMN centroid geography(Point, 4326) NOT NULL');

        DB::statement('CREATE INDEX structures_centroid_gist ON structures USING GIST (centroid)');
        DB::statement('CREATE INDEX structures_footprint_gist ON structures USING GIST (footprint)');

        // Footprints link back once a structure claims them.
        DB::statement(
            'ALTER TABLE external_footprints
             ADD CONSTRAINT external_footprints_matched_structure_id_foreign
             FOREIGN KEY (matched_structure_id) REFERENCES structures (id) ON DELETE SET NULL',
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE external_footprints DROP CONSTRAINT IF EXISTS external_footprints_matched_structure_id_foreign');
        Schema::dropIfExists('structures');
    }
};
