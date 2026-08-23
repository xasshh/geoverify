<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One working period on one device.
 *
 * The trace is a LineString with an M dimension carrying each vertex's epoch
 * timestamp. That is what answers "was the officer there, and when", which is the
 * question the whole register rests on. A LineString without time would prove a
 * route was walked but not that this officer walked it during this session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_sessions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained();
            $table->foreignId('assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            // Time actually spent moving or capturing, not wall clock. A session
            // left open overnight is not eight hours of work.
            $table->unsignedInteger('active_seconds')->default(0);
            $table->unsignedInteger('distance_m')->default(0);
            $table->unsignedInteger('fix_count')->default(0);

            $table->string('app_version', 32)->nullable();

            // Recorded by the device at session start. Never assumed to pass.
            $table->string('integrity_verdict', 32)->default('unverified');

            // Client generated, so a session is addressable before the server has
            // seen it and a retried sync cannot open a second one.
            $table->uuid('client_uuid')->unique();

            $table->timestamps();

            $table->index(['user_id', 'started_at']);
        });

        // LineStringM: X, Y and M, where M is the epoch second of that vertex.
        DB::statement('ALTER TABLE field_sessions ADD COLUMN trace geometry(LineStringM, 4326)');
        DB::statement('CREATE INDEX field_sessions_trace_gist ON field_sessions USING GIST (trace)');
    }

    public function down(): void
    {
        Schema::dropIfExists('field_sessions');
    }
};
