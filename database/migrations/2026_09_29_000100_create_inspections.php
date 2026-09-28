<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M3: a field agent inspects goods before dispatch, or visits the business
 * with or for the buyer, and files a report the buyer approves.
 *
 * One row per protected order, requested the moment the provider says it was
 * paid. The agent's work reaches the field client the way a paid verification
 * visit does: an assignment of its own kind, naming the building, so the
 * officer's board, the console's maps and the review of who was where all see
 * it without learning anything new.
 *
 * The report is the agent's statement, so it is written once: a correction is
 * a new inspection, never an edit. Its photographs are media of kind
 * `inspection` attached to this row, not to the building, which is what keeps
 * them out of every query that gathers a building's evidence. They are shown
 * to this order's buyer and merchant and to nobody else (see CLAUDE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->unique()->constrained();

            // inspection: goods checked before dispatch. site_visit: the
            // business visited, with the buyer or for them.
            $table->string('kind', 16);

            // requested, assigned, submitted, approved, rejected.
            $table->string('status', 16)->default('requested');

            // A site visit's arrangement, as the buyer asked for it.
            $table->timestamp('requested_for')->nullable();
            $table->string('visit_mode', 16)->nullable();

            $table->foreignId('assignment_id')->nullable()->constrained();
            $table->foreignId('agent_id')->nullable()->constrained('users');
            $table->timestamp('assigned_at')->nullable();

            // Arrival: when, and how far from the premises the handset was,
            // measured in PostGIS against the structure. The position itself
            // is kept for audit and never shown to either party.
            $table->timestamp('arrived_at')->nullable();
            $table->decimal('arrival_distance_m', 8, 1)->nullable();
            $table->decimal('arrival_accuracy_m', 8, 2)->nullable();
            $table->geography('arrival_position', 'point', 4326)->nullable();

            // The report. Written once, idempotent on the handset's uuid.
            $table->uuid('report_uuid')->nullable()->unique();
            $table->jsonb('checklist')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();

            // The buyer's word on it.
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('agent_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE inspections
              ADD CONSTRAINT inspections_kind_check CHECK (kind IN ('inspection', 'site_visit')),
              ADD CONSTRAINT inspections_status_check CHECK (status IN ('requested', 'assigned', 'submitted', 'approved', 'rejected')),
              ADD CONSTRAINT inspections_mode_check CHECK (
                (kind = 'site_visit' AND visit_mode IN ('with_me', 'for_me'))
                OR (kind = 'inspection' AND visit_mode IS NULL)),
              ADD CONSTRAINT inspections_report_whole CHECK (
                (submitted_at IS NULL AND checklist IS NULL AND report_uuid IS NULL)
                OR (submitted_at IS NOT NULL AND checklist IS NOT NULL AND report_uuid IS NOT NULL))
        SQL);

        // An inspection names a building, as a verification visit does. The
        // constraint that holds visits to that is widened to hold both.
        DB::statement('ALTER TABLE assignments DROP CONSTRAINT assignments_visit_names_a_structure');
        DB::statement(<<<'SQL'
            ALTER TABLE assignments ADD CONSTRAINT assignments_visit_names_a_structure CHECK (
                (kind IN ('visit', 'inspection') AND structure_id IS NOT NULL)
             OR (kind NOT IN ('visit', 'inspection') AND structure_id IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE assignments DROP CONSTRAINT assignments_visit_names_a_structure');
        DB::statement(<<<'SQL'
            ALTER TABLE assignments ADD CONSTRAINT assignments_visit_names_a_structure CHECK (
                (kind = 'visit' AND structure_id IS NOT NULL)
             OR (kind <> 'visit' AND structure_id IS NULL)
            )
        SQL);

        Schema::dropIfExists('inspections');
    }
};
