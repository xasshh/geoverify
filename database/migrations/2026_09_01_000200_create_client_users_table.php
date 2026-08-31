<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client's own administrator, on their own guard.
 *
 * Not a fourth Role. `users` is the staff table and the rule is that it is never
 * widened to admit somebody who does not work here: Role::supervises() and
 * Role::capturesInTheField() are read all through the field platform, and an
 * NRS administrator one enum value away from the review queue is not a risk
 * worth taking for the convenience of one table.
 *
 * This is the same answer the portal reached for business owners, and for the
 * same reason. Three guards now: `web` for staff, `portal` for parties, `client`
 * for the bodies that commission the work. A session on one can never satisfy
 * the middleware of another, by construction rather than by check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_users', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('client_organisation_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');

            // Scoped to one organisation and one thing: reading what was
            // commissioned. There is no client-side write path in this pass, so
            // there is no role column to get wrong later.
            $table->string('status', 24)->default('active');

            $table->timestamp('last_signed_in_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['client_organisation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_users');
    }
};
