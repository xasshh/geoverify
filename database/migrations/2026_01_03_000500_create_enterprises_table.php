<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A business operating in a structure.
 *
 * One structure has many. Fourteen shops in one building is the norm in Nigerian
 * commercial property, not the exception, which is why unit_label matters and why
 * the capture screen is built around a checklist rather than a single form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprises', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('structure_id')->constrained();

            // Which unit within the structure: G01, Shop 4, First Floor Left.
            $table->string('unit_label', 32)->nullable();

            $table->foreignId('captured_by')->constrained('users');
            $table->timestamp('captured_at');

            // Projection of the latest accepted observation.
            $table->string('trading_name');
            $table->string('registered_name')->nullable();
            $table->string('sector_code', 16)->nullable();
            $table->string('subsector_code', 16)->nullable();
            $table->string('scale_band', 16)->nullable();
            $table->string('operating_status', 24)->nullable();
            $table->string('status', 24)->default('draft');

            $table->uuid('client_uuid')->unique();

            $table->timestamps();

            $table->index(['structure_id', 'status']);
            $table->index('sector_code');
        });

        // Duplicate detection pairs proximity with name similarity: two records
        // metres apart with near identical trading names are one business counted
        // twice, which inflates a register a client is paying for.
        DB::statement(
            'CREATE INDEX enterprises_trading_name_trgm ON enterprises USING GIN (trading_name gin_trgm_ops)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprises');
    }
};
