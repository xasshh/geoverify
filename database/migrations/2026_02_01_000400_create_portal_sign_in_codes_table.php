<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time sign-in codes, and the record of what was attempted.
 *
 * The code itself is hashed. A one-time code is a credential for the minutes it
 * lives, and a readable column holding one is a readable column holding a way
 * into somebody's business.
 *
 * Attempts are counted on the row rather than only in a rate limiter, because
 * the brief is explicit that a lockout must not lock a legitimate user out of
 * their own business for a day. Counting per code lets a wrong guess burn that
 * code without burning the phone number: the person asks for another and
 * carries on, while an attacker guessing at a six digit space is stopped after
 * five tries per code and throttled on requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_sign_in_codes', function (Blueprint $table): void {
            $table->id();

            // Held against the phone rather than the account, because a code is
            // sent before we know whether this is a registration or a sign-in.
            $table->string('phone', 32);

            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();

            // For the audit trail, and for telling a person where a code they
            // did not request was asked for.
            $table->string('request_ip', 45)->nullable();

            $table->timestamps();

            $table->index(['phone', 'consumed_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_sign_in_codes');
    }
};
