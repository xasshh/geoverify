<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a structure looked like on one visit.
 *
 * The append only authority behind the projected columns on structures. A revisit
 * in 2027 writes a new row here; March stays exactly as March was recorded. This
 * is what lets the register answer "what did this look like then" as well as
 * "what does it look like now", which is the difference between a register and a
 * snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('structure_observations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('structure_id')->constrained()->cascadeOnDelete();

            // Provenance, on every observation. A row without it is not evidence.
            $table->foreignId('captured_by')->constrained('users');
            $table->foreignId('field_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('observed_at');
            $table->decimal('capture_accuracy_m', 8, 2)->nullable();

            // The time varying attributes. Everything here can differ between one
            // visit and the next.
            $table->string('structure_type', 32);
            $table->string('layout_class', 32)->nullable();
            $table->unsignedSmallInteger('floors')->nullable();
            $table->unsignedSmallInteger('unit_count')->nullable();
            $table->string('condition', 24)->nullable();
            $table->string('occupancy_status', 32);
            $table->text('notes')->nullable();

            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->string('status', 24)->default('submitted');

            $table->uuid('client_uuid')->unique();

            $table->timestamps();

            $table->index(['structure_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structure_observations');
    }
};
