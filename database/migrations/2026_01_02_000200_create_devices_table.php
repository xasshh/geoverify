<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The handsets work is captured on.
 *
 * A device is enrolled to one officer and carries its own revocable token, so a
 * lost phone is cut off without touching the person's account. Its public key is
 * what lets the server tell a signed submission from a forged one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The client generated identifier the handset reports on every sync.
            $table->string('device_id', 128)->unique();

            $table->string('model')->nullable();
            $table->string('os_version', 64)->nullable();
            $table->string('app_version', 32)->nullable();

            // Sync payloads are signed with the matching private key, held only on
            // the handset. Without it a submission could be forged outside the app.
            $table->text('public_key')->nullable();

            // Dual frequency GNSS gives metre level accuracy instead of five to ten.
            // Recorded because it changes what a given accuracy reading means.
            $table->boolean('gnss_dual_frequency')->default(false);

            // Play Integrity at session start. Never assumed to have passed: a
            // handset that cannot attest is recorded as unverified and weighted
            // accordingly, which is not the same as being trusted.
            $table->string('integrity_verdict', 32)->default('unverified');
            $table->timestamp('integrity_checked_at')->nullable();

            $table->string('status', 24)->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
