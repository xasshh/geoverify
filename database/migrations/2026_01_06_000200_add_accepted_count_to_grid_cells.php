<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a cell has been accepted, as opposed to merely visited.
 *
 * structures_captured answers "did an officer go there". Before review existed
 * that was the only question the coverage view could ask, and it was the right
 * one. Now that a supervisor can accept or return work, a mandate that is 90 per
 * cent captured and 20 per cent accepted is in a completely different state from
 * one where those numbers agree, and a coverage map that cannot tell them apart
 * will report a job as nearly finished when most of it is still contested.
 *
 * Maintained by recomputation from the structures table rather than by
 * incrementing, exactly like structures_captured, so a retried decision cannot
 * inflate it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grid_cells', function (Blueprint $table): void {
            $table->unsignedInteger('structures_accepted')->default(0)->after('structures_captured');
        });
    }

    public function down(): void
    {
        Schema::table('grid_cells', function (Blueprint $table): void {
            $table->dropColumn('structures_accepted');
        });
    }
};
