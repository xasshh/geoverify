<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one extension to the field platform, made once, with both paths using it.
 *
 * Two things stopped `assignments` expressing a paid visit.
 *
 * The unique index allowed one open assignment per cell, so a paid order
 * against a cell an officer is already sweeping could not create its assignment
 * at all: the insert violated the index. That is not a rare edge. A mandate
 * sweep and a paid order in the same ward is the normal commercial case.
 *
 * And `assignments` was cell-scoped. A sweep covers 626 buildings; a paid visit
 * targets one, and there was nowhere to say which.
 *
 * So: a `kind`, a nullable `structure_id` meaningful only for a visit, a
 * `priority`, and the index rewritten to cover sweeps alone. Sweeps keep their
 * exclusivity exactly as today. Visits are unconstrained, because several paid
 * visits in one cell is a good problem to have.
 *
 * Nothing else moves. The field client, the sync contract and AssignCells keep
 * calling exactly what they called before, and every existing row is a sweep by
 * default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table): void {
            $table->string('kind', 24)->default('sweep')->after('id');

            // Meaningful only for a verification visit. Null on a sweep, which
            // covers a cell rather than a building.
            $table->foreignId('structure_id')->nullable()->after('grid_cell_id')
                ->constrained()->nullOnDelete();

            // Higher sorts first on the officer's board. A paid visit with an
            // SLA behind it outranks a sweep that has all quarter.
            $table->smallInteger('priority')->default(0);

            $table->index(['kind', 'priority']);
        });

        // A visit must name a building and a sweep must not, checked rather
        // than assumed. A visit with no structure is an officer sent to a cell
        // with no idea which door, which is the failure this whole extension
        // exists to prevent.
        DB::statement(<<<'SQL'
            ALTER TABLE assignments ADD CONSTRAINT assignments_visit_names_a_structure CHECK (
                (kind = 'visit' AND structure_id IS NOT NULL)
             OR (kind <> 'visit' AND structure_id IS NULL)
            )
        SQL);

        DB::statement('DROP INDEX IF EXISTS assignments_one_open_per_cell');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX assignments_one_open_sweep_per_cell
            ON assignments (grid_cell_id)
            WHERE closed_at IS NULL AND kind = 'sweep'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS assignments_one_open_sweep_per_cell');
        DB::statement('ALTER TABLE assignments DROP CONSTRAINT IF EXISTS assignments_visit_names_a_structure');

        DB::statement(
            'CREATE UNIQUE INDEX assignments_one_open_per_cell
             ON assignments (grid_cell_id) WHERE closed_at IS NULL',
        );

        Schema::table('assignments', function (Blueprint $table): void {
            $table->dropIndex(['kind', 'priority']);
            $table->dropConstrainedForeignId('structure_id');
            $table->dropColumn(['kind', 'priority']);
        });
    }
};
