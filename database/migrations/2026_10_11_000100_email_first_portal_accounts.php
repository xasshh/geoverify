<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portal accounts start with an email, not a phone.
 *
 * Decided on 2026-10-09: no SMS anywhere. An account is opened with an email
 * and a password and is usable once the email is proved by a signed link. The
 * phone becomes an optional contact. Accounts already proved by phone stay
 * valid, which is why the check asks for one or the other rather than an
 * email outright.
 *
 * Party team invitations and Enumerate seats follow the account: a seat waits
 * on an email address the way it waited on a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_accounts', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->change();
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE portal_accounts
              ADD CONSTRAINT portal_accounts_reachable CHECK (phone IS NOT NULL OR email IS NOT NULL)
        SQL);
        DB::statement('CREATE UNIQUE INDEX portal_accounts_email_lower ON portal_accounts (lower(email)) WHERE email IS NOT NULL');

        Schema::table('parties', function (Blueprint $table): void {
            $table->string('primary_phone', 32)->nullable()->change();
        });

        Schema::create('portal_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('enumerate_organisation_members', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable()->change();
            $table->string('email', 180)->nullable()->after('phone');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE enumerate_organisation_members
              ADD CONSTRAINT enumerate_members_addressed CHECK (phone IS NOT NULL OR email IS NOT NULL);

            CREATE UNIQUE INDEX enumerate_members_one_live_per_email
              ON enumerate_organisation_members (organisation_id, lower(email)) WHERE revoked_at IS NULL AND email IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS enumerate_members_one_live_per_email;
            ALTER TABLE enumerate_organisation_members DROP CONSTRAINT IF EXISTS enumerate_members_addressed;
            DROP INDEX IF EXISTS portal_accounts_email_lower;
            ALTER TABLE portal_accounts DROP CONSTRAINT IF EXISTS portal_accounts_reachable;
        SQL);

        Schema::table('enumerate_organisation_members', function (Blueprint $table): void {
            $table->dropColumn('email');
        });

        Schema::dropIfExists('portal_password_reset_tokens');

        Schema::table('portal_accounts', function (Blueprint $table): void {
            $table->dropColumn('email_verified_at');
        });
    }
};
