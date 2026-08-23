<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every recorded fix, kept raw and never smoothed.
 *
 * The trace on field_sessions is a summary. This is the evidence. Anti-spoofing
 * works by looking at what the receiver actually reported: a mock location flag,
 * a satellite count of zero, a network position that disagrees with the GNSS one,
 * accuracy that never varies. Smoothing or thinning these would destroy exactly
 * the signal that catches a fabricated day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_fixes', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('field_session_id')->constrained()->cascadeOnDelete();

            $table->timestamp('recorded_at');

            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('altitude_m', 8, 2)->nullable();
            $table->decimal('speed_mps', 8, 2)->nullable();
            $table->decimal('heading', 6, 2)->nullable();
            $table->unsignedSmallInteger('satellite_count')->nullable();
            $table->decimal('hdop', 6, 2)->nullable();

            // The device's own answer to "is a mock location provider active". A
            // single true here is a hard flag: it is free to install one and it
            // takes thirty seconds.
            $table->boolean('is_mock')->default(false);

            // gps, network or fused. A day of fused-only fixes indoors reads
            // differently from a day of satellite fixes on the street.
            $table->string('provider', 16)->nullable();

            $table->string('source', 24)->default('device');

            $table->timestamps();

            $table->index(['field_session_id', 'recorded_at']);
            $table->index('is_mock');
        });

        DB::statement('ALTER TABLE position_fixes ADD COLUMN point geometry(Point, 4326) NOT NULL');

        // The cell tower or wifi derived position, when the device reports one.
        // Divergence between this and the GNSS point is one of the cheapest ways
        // to catch a spoofed location, because most mock apps only move the GNSS.
        DB::statement('ALTER TABLE position_fixes ADD COLUMN network_point geometry(Point, 4326)');

        DB::statement('CREATE INDEX position_fixes_point_gist ON position_fixes USING GIST (point)');
    }

    public function down(): void
    {
        Schema::dropIfExists('position_fixes');
    }
};
