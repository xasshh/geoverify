<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The code sent to the number an officer wrote down at the shop.
     *
     * Deliberately not the sign-in code table. That one proves you hold the
     * phone you registered with; this one proves you hold a phone we already
     * associated with a business, which is a different assertion with a
     * different blast radius. Sharing the table would mean a code issued for
     * one purpose could be presented for the other.
     *
     * The number itself is never stored here and never leaves the server. The
     * claimant is shown a mask and has to recognise it. Otherwise the claim
     * screen becomes a way to read a phone number off every business in the
     * register, one claim at a time.
     */
    public function up(): void
    {
        Schema::create('claim_phone_codes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();

            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->string('request_ip', 45)->nullable();

            $table->timestamps();

            $table->index(['claim_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_phone_codes');
    }
};
