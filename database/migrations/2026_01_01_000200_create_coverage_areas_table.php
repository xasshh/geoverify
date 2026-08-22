<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A mandate's geography: the ground a client has actually contracted to have
 * enumerated. Everything downstream, the grid, the assignments and the coverage
 * denominator, is bounded by one of these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverage_areas', function (Blueprint $table): void {
            $table->id();

            $table->string('client_name');
            $table->string('contract_ref')->nullable();
            $table->string('name');

            // Denormalised for reporting. The authority is the boundary itself and
            // the admin_boundaries rows it was cut from.
            $table->string('state_code', 32)->nullable();
            $table->string('lga_code', 32)->nullable();

            $table->foreignId('admin_boundary_id')->nullable()
                ->constrained('admin_boundaries')->nullOnDelete();

            $table->string('status', 24)->default('draft');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            // Mandate thresholds. A capture above this accuracy is deducted against
            // during scoring, so it is contract data, not a global constant.
            $table->unsignedSmallInteger('accuracy_threshold_m')->default(15);
            $table->unsignedTinyInteger('default_h3_resolution')->default(9);

            $table->timestamps();

            $table->index(['status', 'lga_code']);
        });

        DB::statement('ALTER TABLE coverage_areas ADD COLUMN boundary geometry(MultiPolygon, 4326) NOT NULL');
        DB::statement('CREATE INDEX coverage_areas_boundary_gist ON coverage_areas USING GIST (boundary)');
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_areas');
    }
};
