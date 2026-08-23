<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Officer to cell: who is responsible for which ground, and by when.
 *
 * An assignment is never deleted. Reassigning a cell closes the previous
 * assignment and opens a new one, so the record of who held which ground on a
 * given day survives, which is the question an auditor asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('grid_cell_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('assigned_by')->constrained('users');

            $table->timestamp('assigned_at');
            $table->date('due_on')->nullable();

            // assigned, in_progress, submitted, accepted, returned, reassigned.
            $table->string('status', 24)->default('assigned');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');

            // Why a supervisor sent this back. Shown to the officer verbatim, so it
            // is written for them to act on rather than as an internal note.
            $table->text('return_reason')->nullable();

            // Set when this assignment is superseded, which is what keeps history
            // intact instead of overwriting the holder.
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['grid_cell_id', 'status']);
            $table->index('due_on');
        });

        // One live assignment per cell. Closed rows are unconstrained, so the full
        // history of who held a cell stays queryable while two officers can never
        // be sent to the same ground at once.
        DB::statement(
            'CREATE UNIQUE INDEX assignments_one_open_per_cell
             ON assignments (grid_cell_id) WHERE closed_at IS NULL',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
