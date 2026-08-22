<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loaded reference data, never user-created.
 *
 * This table is the authority on where a capture happened. Every captured point is
 * resolved into state, LGA and ward here by ST_Contains at ingestion, and a
 * client-supplied ward name is never trusted, because a device can claim anything
 * and the register has to survive a challenge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_boundaries', function (Blueprint $table): void {
            $table->id();

            // state, lga, ward. Nigeria: 37 states, 774 LGAs, roughly 8,800 wards.
            $table->string('level', 16);

            // The official code for the level: OCHA pcodes for state and LGA
            // (NG015, NG015002), a derived key for wards, which have no published
            // pcode. Sized to hold the full derived key without truncation. The
            // longest national ward key is 48 characters, and truncating them
            // collides distinct wards ("Balogun Fulani I" against "II" against
            // "III"), which would resolve a capture into the wrong ward.
            $table->string('code', 96);
            $table->string('name');

            // Alternative spellings. Nigerian place names vary between sources and
            // an officer searching "Abuja Municipal" must find "Municipal Area Council".
            $table->jsonb('alt_names')->default(DB::raw("'[]'::jsonb"));

            $table->foreignId('parent_id')->nullable()
                ->constrained('admin_boundaries')->nullOnDelete();

            $table->string('source', 64);
            $table->string('source_ref')->nullable();

            // What the source itself claims the parent is called. parent_id remains
            // the authority, resolved by containment, but the two disagree often
            // enough to matter: OCHA and GRID3 draw the AMAC and Bwari line
            // differently, so a handful of wards attach to a different LGA than
            // their own publisher says. That is a finding a client can challenge,
            // so it is recorded rather than smoothed over.
            $table->string('source_parent_name')->nullable();
            $table->date('source_vintage')->nullable();

            $table->timestamps();

            $table->unique(['level', 'code']);
            $table->index(['level', 'name']);
            $table->index('parent_id');
        });

        // MultiPolygon: several Nigerian LGAs and wards are genuinely multipart.
        DB::statement('ALTER TABLE admin_boundaries ADD COLUMN boundary geometry(MultiPolygon, 4326) NOT NULL');
        DB::statement('CREATE INDEX admin_boundaries_boundary_gist ON admin_boundaries USING GIST (boundary)');

        // Point-in-polygon resolution runs on every ingested capture, so the level
        // filter that precedes it needs to be cheap too.
        DB::statement('CREATE INDEX admin_boundaries_level_boundary_gist ON admin_boundaries USING GIST (boundary) WHERE level = \'ward\'');

        // Trigram search over names, for the console's boundary picker.
        DB::statement('CREATE INDEX admin_boundaries_name_trgm ON admin_boundaries USING GIN (name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_boundaries');
    }
};
