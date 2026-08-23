<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a business looked like on one visit. Append only, like structures. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_observations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();

            $table->foreignId('captured_by')->constrained('users');
            $table->foreignId('field_session_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('observed_at');

            $table->string('trading_name');
            $table->string('registered_name')->nullable();

            // ISIC Rev 4. The officer never types a code: they search a curated
            // list of Nigerian trade names that map onto one underneath.
            $table->string('sector_code', 16)->nullable();
            $table->string('subsector_code', 16)->nullable();

            $table->string('scale_band', 16)->nullable();
            $table->string('employee_band', 16)->nullable();
            $table->string('operating_status', 24);
            $table->unsignedSmallInteger('years_at_location')->nullable();

            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('opening_hours')->nullable();

            // Whether the officer could actually see a sign. A business with no
            // signage is harder to verify later, so it changes the confidence.
            $table->boolean('signage_observed')->default(false);

            $table->text('notes')->nullable();
            $table->string('status', 24)->default('submitted');

            $table->uuid('client_uuid')->unique();

            $table->timestamps();

            $table->index(['enterprise_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_observations');
    }
};
