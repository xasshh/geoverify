<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person who signs in to the portal.
 *
 * The brief describes `party_users` as "a party can have several people with
 * access", and names a `user_id` on it. That column cannot point at `users`:
 * that table requires an email and a password, and the primary audience here
 * signs in with a phone number and may have neither. It also cannot BE the
 * account, because one person legitimately holds access to several parties (an
 * agent managing four shops is one person), and folding the account into the
 * membership would mean four rows, four phone numbers, four sign-ins.
 *
 * So the account is its own table and `party_users` stays what the brief says
 * it is: the membership between a person and a party.
 *
 * Phone is the primary credential. Password is optional and secondary, because
 * requiring one costs registrations from an audience that does not want another
 * password and will not remember it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_accounts', function (Blueprint $table): void {
            $table->id();

            $table->string('name');

            // The credential. Stored normalised to E.164 so the same person
            // cannot arrive twice as 0803... and +234803...
            $table->string('phone', 32)->unique();
            $table->timestamp('phone_verified_at')->nullable();

            // Both optional. Email is a convenience for a company account that
            // wants receipts somewhere; a password is a second factor, never
            // the first.
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();

            $table->string('status', 24)->default('active');
            $table->timestamp('last_signed_in_at')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_accounts');
    }
};
