<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detected building footprints: the denominator, and the officer's work list.
 *
 * These are a work list, not a record. The sources miss buildings, merge adjacent
 * ones and occasionally invent them, so an officer can add a structure with no
 * matching footprint and can mark a footprint as not a building. What they buy us
 * is a completion figure that does not depend on what the officer claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_footprints', function (Blueprint $table): void {
            $table->id();

            // google_open_buildings or microsoft_global_ml.
            $table->string('source', 48);
            $table->string('source_id', 64)->nullable();

            // The source's own confidence in the detection, 0 to 1. Nullable because
            // it is genuinely absent for some regions: Microsoft publishes -1 for
            // Nigeria, meaning unscored, which is stored as null rather than as a
            // fabricated number.
            $table->decimal('confidence', 4, 3)->nullable();
            $table->decimal('area_m2', 10, 2)->nullable();

            $table->bigInteger('h3_index')->nullable();
            $table->foreignId('grid_cell_id')->nullable()
                ->constrained()->nullOnDelete();

            // Populated at M4 when structures exist. The foreign key is added with
            // that table rather than left dangling here.
            $table->unsignedBigInteger('matched_structure_id')->nullable();

            // An officer's judgement that the detector was wrong. Kept rather than
            // deleted: nothing in this system is hard-deleted, and a rejected
            // footprint is evidence about the source's quality.
            $table->boolean('dismissed')->default(false);

            $table->timestamps();

            // Idempotency. Sources publish no stable identifier, so source_id is a
            // digest of the geometry: re-running an ingest updates rather than
            // duplicates, which is what makes the command resumable.
            $table->unique(['source', 'source_id']);
            $table->index(['grid_cell_id', 'dismissed']);
            $table->index('h3_index');
        });

        DB::statement('ALTER TABLE external_footprints ADD COLUMN footprint geometry(Polygon, 4326) NOT NULL');
        DB::statement('CREATE INDEX external_footprints_footprint_gist ON external_footprints USING GIST (footprint)');
    }

    public function down(): void
    {
        Schema::dropIfExists('external_footprints');
    }
};
