<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The token behind the QR code on a certificate.
 *
 * Somebody holding a printed certificate needs to be able to ask this system
 * whether it is real, without an account and without our having to trust the
 * paper in their hand. So the QR carries an opaque token and the answer comes
 * from here rather than from the document.
 *
 * The token is random and long. It is not the order id, not the enterprise id
 * and not a hash of either: a token you can derive from a reference printed on
 * the same page is a token that lets somebody enumerate every verification we
 * have ever issued by counting.
 *
 * Nothing about the business is stored here. The page reads through to the
 * order and the enterprise at request time on purpose, because a party who
 * withdraws publication has to stop being published now rather than whenever a
 * cached projection is next rebuilt. That is the M7 gate and it is also the
 * only honest way to hold a revocation promise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_verifications', function (Blueprint $table): void {
            $table->id();

            $table->string('token', 64)->unique();

            $table->foreignId('verification_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();

            $table->timestamp('issued_at');

            // A tier can go stale. The certificate says when it was established
            // and the page says whether that is still current, so neither has
            // to pretend a check from two years ago means the same as one from
            // last week.
            $table->date('valid_until')->nullable();

            // Killed deliberately: a certificate issued in error, or a business
            // whose verification was overturned on appeal. Separate from
            // publication state, which the party controls and this does not.
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();

            $table->timestamps();

            $table->index(['enterprise_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_verifications');
    }
};
