<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Polling unit checks (2026-10-15): before an election, an officer visits each
 * polling unit on a list, says whether it is where the list puts it (and
 * where it really is), and records whether voters can use it.
 *
 * `polling_units` is the list as it was given to us, usually INEC's: its code,
 * name and the state, LGA and ward it was listed under, kept as the text we
 * were sent. Those names are a client's say-so, so the boundary ids beside
 * them are resolved in PostGIS from a point (the confirmed one once an officer
 * has stood there, the listed one before), never from the names.
 *
 * `polling_unit_checks` are the visits. A check is written once: sending it
 * back closes it as returned and opens the next, and a second visit to a unit
 * already confirmed is a new check, never an edit of the first. The unit keeps
 * a pointer to the check that last settled it and the point that check found.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polling_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained();
            // INEC's delimitation, for example 24-06-04-012 (state, LGA, ward, unit).
            $table->string('inec_code', 40);
            $table->string('name', 300);
            $table->text('description')->nullable();
            $table->string('listed_state', 120)->nullable();
            $table->string('listed_lga', 120)->nullable();
            $table->string('listed_ward', 160)->nullable();
            $table->unsignedInteger('registered_voters')->nullable();
            $table->geography('listed_point', 'point', 4326)->nullable();

            // listed, assigned, filed, confirmed, not_found.
            $table->string('status', 16)->default('listed');
            $table->geography('confirmed_point', 'point', 4326)->nullable();
            $table->decimal('confirmed_accuracy_m', 8, 2)->nullable();
            // How far the unit really is from where the list put it.
            $table->decimal('listed_distance_m', 10, 1)->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->foreignId('state_id')->nullable()->constrained('admin_boundaries');
            $table->foreignId('lga_id')->nullable()->constrained('admin_boundaries');
            $table->foreignId('ward_id')->nullable()->constrained('admin_boundaries');

            $table->foreignId('imported_by')->constrained('users');
            $table->timestamps();

            $table->unique(['campaign_id', 'inec_code']);
            $table->index(['campaign_id', 'status']);
            $table->index(['campaign_id', 'listed_lga', 'listed_ward']);
        });

        Schema::create('polling_unit_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('polling_unit_id')->constrained();
            $table->foreignId('campaign_id')->constrained();
            // assigned, submitted, accepted, returned.
            $table->string('status', 16)->default('assigned');

            $table->foreignId('agent_id')->constrained('users');
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamp('assigned_at');

            // Where the officer stood when they said they were at the unit.
            $table->timestamp('arrived_at')->nullable();
            $table->geography('arrival_position', 'point', 4326)->nullable();
            $table->decimal('arrival_accuracy_m', 8, 2)->nullable();
            $table->decimal('arrival_distance_m', 10, 1)->nullable();

            // The report. Written once, idempotent on the handset's uuid.
            $table->uuid('report_uuid')->nullable()->unique();
            // at_listed, elsewhere, not_found.
            $table->string('found', 16)->nullable();
            $table->jsonb('answers')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();

            $table->timestamps();

            $table->index(['agent_id', 'status']);
            $table->index(['campaign_id', 'status', 'submitted_at']);
        });

        Schema::table('polling_units', function (Blueprint $table): void {
            $table->foreignId('settled_by_check_id')->nullable()->after('settled_at')->constrained('polling_unit_checks');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE polling_units
              ADD CONSTRAINT polling_units_status_check CHECK (status IN ('listed', 'assigned', 'filed', 'confirmed', 'not_found')),
              ADD CONSTRAINT polling_units_confirmed_has_point CHECK (status <> 'confirmed' OR confirmed_point IS NOT NULL),
              ADD CONSTRAINT polling_units_settled_whole CHECK (
                (status IN ('confirmed', 'not_found')) = (settled_by_check_id IS NOT NULL) OR status IN ('assigned', 'filed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE polling_unit_checks
              ADD CONSTRAINT polling_unit_checks_status_check CHECK (status IN ('assigned', 'submitted', 'accepted', 'returned')),
              ADD CONSTRAINT polling_unit_checks_found_check CHECK (found IS NULL OR found IN ('at_listed', 'elsewhere', 'not_found')),
              ADD CONSTRAINT polling_unit_checks_report_whole CHECK (
                (submitted_at IS NULL AND answers IS NULL AND report_uuid IS NULL AND found IS NULL)
                OR (submitted_at IS NOT NULL AND answers IS NOT NULL AND report_uuid IS NOT NULL AND found IS NOT NULL)),
              ADD CONSTRAINT polling_unit_checks_review_whole CHECK (
                (reviewed_at IS NULL AND reviewed_by IS NULL) OR (reviewed_at IS NOT NULL AND reviewed_by IS NOT NULL)),
              ADD CONSTRAINT polling_unit_checks_returned_has_reason CHECK (status <> 'returned' OR review_note IS NOT NULL)
        SQL);

        // One open check per unit: two officers sent to the same school is a
        // mistake the database refuses on its own.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX polling_unit_checks_one_open
            ON polling_unit_checks (polling_unit_id) WHERE status IN ('assigned', 'submitted')
        SQL);

        DB::statement('ALTER TABLE campaigns DROP CONSTRAINT campaigns_capture_modes_known');
        DB::statement(<<<'SQL'
            ALTER TABLE campaigns ADD CONSTRAINT campaigns_capture_modes_known CHECK (
              jsonb_typeof(capture_modes) = 'array'
              AND jsonb_array_length(capture_modes) > 0
              AND capture_modes <@ '["buildings", "area_features", "polling_units"]'::jsonb)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE campaigns DROP CONSTRAINT campaigns_capture_modes_known');
        DB::statement(<<<'SQL'
            ALTER TABLE campaigns ADD CONSTRAINT campaigns_capture_modes_known CHECK (
              jsonb_typeof(capture_modes) = 'array'
              AND jsonb_array_length(capture_modes) > 0
              AND capture_modes <@ '["buildings", "area_features"]'::jsonb)
        SQL);

        Schema::table('polling_units', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('settled_by_check_id');
        });
        Schema::dropIfExists('polling_unit_checks');
        Schema::dropIfExists('polling_units');
    }
};
