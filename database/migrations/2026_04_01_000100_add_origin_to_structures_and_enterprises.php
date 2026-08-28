<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two ways a record can come into being, told apart forever.
     *
     * Until now every structure was captured by an officer standing in front of
     * it, and the schema said so: captured_by, grid_cell_id and
     * coverage_area_id were all NOT NULL because there was no other way in. A
     * business registering itself has none of the three. It has no officer, and
     * it may sit outside any mandate we hold.
     *
     * The columns become nullable, and a CHECK constraint replaces what the
     * NOT NULLs were really enforcing: not "this column has a value" but "this
     * record has one of exactly two shapes". A nullable column with no
     * constraint would let a self-registration masquerade as field work, which
     * is the one thing that must never happen: the whole product rests on the
     * difference between what an officer saw and what somebody typed.
     */
    public function up(): void
    {
        Schema::table('structures', function (Blueprint $table) {
            $table->string('origin')->default('field');
            $table->foreignId('registered_by_party_id')->nullable()->constrained('parties');
        });

        DB::statement('ALTER TABLE structures ALTER COLUMN captured_by DROP NOT NULL');
        DB::statement('ALTER TABLE structures ALTER COLUMN grid_cell_id DROP NOT NULL');
        DB::statement('ALTER TABLE structures ALTER COLUMN coverage_area_id DROP NOT NULL');

        // A self-registration carries no grid cell, and that is load bearing
        // rather than tidiness. Cell progress is a count of structures in the
        // cell, so a self-registered record holding one would inflate the
        // coverage percentage a supervisor uses to judge whether ground has
        // actually been swept. An officer's progress must mean officer work.
        DB::statement(<<<'SQL'
            ALTER TABLE structures ADD CONSTRAINT structures_origin_shape CHECK (
                (origin = 'field'
                    AND captured_by IS NOT NULL
                    AND grid_cell_id IS NOT NULL
                    AND coverage_area_id IS NOT NULL
                    AND registered_by_party_id IS NULL)
             OR (origin = 'self_registered'
                    AND captured_by IS NULL
                    AND grid_cell_id IS NULL
                    AND registered_by_party_id IS NOT NULL)
            )
        SQL);

        Schema::table('enterprises', function (Blueprint $table) {
            $table->string('origin')->default('field');
            $table->foreignId('registered_by_party_id')->nullable()->constrained('parties');
        });

        DB::statement('ALTER TABLE enterprises ALTER COLUMN captured_by DROP NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE enterprises ADD CONSTRAINT enterprises_origin_shape CHECK (
                (origin = 'field'
                    AND captured_by IS NOT NULL
                    AND registered_by_party_id IS NULL)
             OR (origin = 'self_registered'
                    AND captured_by IS NULL
                    AND registered_by_party_id IS NOT NULL)
            )
        SQL);

        // The queue a supervisor works when confirming self-registrations, and
        // the join the portal makes constantly. Partial, because field records
        // outnumber these by orders of magnitude and never match.
        DB::statement(<<<'SQL'
            CREATE INDEX structures_self_registered
            ON structures (registered_by_party_id, status)
            WHERE origin = 'self_registered'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS structures_self_registered');
        DB::statement('ALTER TABLE enterprises DROP CONSTRAINT IF EXISTS enterprises_origin_shape');
        DB::statement('ALTER TABLE structures DROP CONSTRAINT IF EXISTS structures_origin_shape');

        Schema::table('enterprises', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registered_by_party_id');
            $table->dropColumn('origin');
        });

        Schema::table('structures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registered_by_party_id');
            $table->dropColumn('origin');
        });
    }
};
