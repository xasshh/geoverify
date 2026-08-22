<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The unit of work.
 *
 * One H3 cell is what an officer is assigned, works, and submits. Resolution 9 is
 * roughly 0.1 square kilometres, which is about a morning's work in dense
 * commercial ground.
 *
 * h3_index is stored as bigint rather than the h3index type so that the column is
 * portable and joins cheaply; the conversion is lossless and is asserted in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grid_cells', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('coverage_area_id')->constrained()->cascadeOnDelete();

            $table->bigInteger('h3_index')->unique();
            $table->unsignedTinyInteger('h3_resolution');
            $table->bigInteger('parent_h3_index')->nullable();

            // The denominator. Set by footprint ingest, not by an officer's claim.
            $table->unsignedInteger('footprint_count')->default(0);
            $table->unsignedInteger('structures_captured')->default(0);

            $table->string('status', 24)->default('unassigned');

            // Derived from the two counts above. Stored so the coverage map can be
            // rendered without recomputing across a whole mandate on every request.
            $table->decimal('coverage_pct', 5, 2)->default(0);

            $table->timestamps();

            $table->index(['coverage_area_id', 'status']);
            $table->index('parent_h3_index');
        });

        DB::statement('ALTER TABLE grid_cells ADD COLUMN boundary geometry(Polygon, 4326) NOT NULL');
        DB::statement('ALTER TABLE grid_cells ADD COLUMN centroid geography(Point, 4326) NOT NULL');

        DB::statement('CREATE INDEX grid_cells_boundary_gist ON grid_cells USING GIST (boundary)');
        DB::statement('CREATE INDEX grid_cells_centroid_gist ON grid_cells USING GIST (centroid)');
    }

    public function down(): void
    {
        Schema::dropIfExists('grid_cells');
    }
};
