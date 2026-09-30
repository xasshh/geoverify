<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enumerate E2: an officer visits the business somebody paid to have checked.
 *
 * Not an `assignments` row. A sweep and a paid visit to a building both name
 * a cell or a structure of our register, and an Enumerate subject is usually
 * neither: it is a CAC record at an address no officer has mapped. So the job
 * is its own record, and reaches the officer's Today screen the way an
 * inspection does, without the sync contract learning anything.
 *
 * Where to go is the supervisor's pin, placed from the CAC registered address.
 * It is staff input, checked against Nigeria's extent and resolved to a ward
 * and LGA in PostGIS, and it is never shown to the requester: they are told
 * how far the premises the officer found are from the registered address, and
 * the ward and LGA, and nothing that places a pin.
 *
 * A visit is written once. Sending it back closes it as returned and opens the
 * next visit; nothing an officer filed is overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enumerate_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enumerate_request_id')->constrained();
            // site: the Tier 2 visit (and Tier 3's first). monitoring arrives with E3.
            $table->string('kind', 16)->default('site');
            // assigned, submitted, accepted, returned.
            $table->string('status', 16)->default('assigned');

            $table->foreignId('agent_id')->constrained('users');
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamp('assigned_at');

            $table->geography('site_point', 'point', 4326);
            $table->foreignId('ward_id')->nullable()->constrained('admin_boundaries');
            $table->foreignId('lga_id')->nullable()->constrained('admin_boundaries');

            // Arrival, measured in PostGIS against the pin. The handset's
            // position is kept for audit and shown to nobody outside staff.
            $table->timestamp('arrived_at')->nullable();
            $table->decimal('arrival_distance_m', 9, 1)->nullable();
            $table->decimal('arrival_accuracy_m', 8, 2)->nullable();
            $table->geography('arrival_position', 'point', 4326)->nullable();

            // The report. Written once, idempotent on the handset's uuid.
            $table->uuid('report_uuid')->nullable()->unique();
            $table->jsonb('checklist')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();

            // The supervisor's word on it.
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();

            $table->timestamps();

            $table->index(['enumerate_request_id', 'assigned_at']);
            $table->index(['agent_id', 'status']);
            $table->index(['status', 'submitted_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE enumerate_visits
              ADD CONSTRAINT enumerate_visits_kind_check CHECK (kind IN ('site')),
              ADD CONSTRAINT enumerate_visits_status_check CHECK (status IN ('assigned', 'submitted', 'accepted', 'returned')),
              ADD CONSTRAINT enumerate_visits_report_whole CHECK (
                (submitted_at IS NULL AND checklist IS NULL AND report_uuid IS NULL)
                OR (submitted_at IS NOT NULL AND checklist IS NOT NULL AND report_uuid IS NOT NULL)),
              ADD CONSTRAINT enumerate_visits_review_whole CHECK (
                (reviewed_at IS NULL AND reviewed_by IS NULL) OR (reviewed_at IS NOT NULL AND reviewed_by IS NOT NULL)),
              ADD CONSTRAINT enumerate_visits_returned_has_reason CHECK (
                status <> 'returned' OR review_note IS NOT NULL)
        SQL);

        // One open visit per request: two officers sent to the same shop is a
        // mistake the database can refuse on its own.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX enumerate_visits_one_open
            ON enumerate_visits (enumerate_request_id) WHERE status IN ('assigned', 'submitted')
        SQL);

        DB::statement('ALTER TABLE enumerate_requests DROP CONSTRAINT enumerate_requests_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE enumerate_requests ADD CONSTRAINT enumerate_requests_status_check CHECK (status IN (
              'paid', 'registry_check', 'passed', 'failed', 'awaiting_agent',
              'agent_assigned', 'on_site', 'monitoring', 'completed'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE enumerate_requests DROP CONSTRAINT enumerate_requests_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE enumerate_requests ADD CONSTRAINT enumerate_requests_status_check CHECK (status IN (
              'paid', 'registry_check', 'passed', 'failed', 'awaiting_agent'))
        SQL);

        Schema::dropIfExists('enumerate_visits');
    }
};
