<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Continue with Google" for portal accounts (2026-10-09).
 *
 * The Google subject id is kept beside the email it proved, so a later sign
 * in with the same Google account finds the same portal account even if the
 * address on the Google side changes. Portal accounts only: staff never sign
 * in this way, because staff are created by an administrator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_accounts', function (Blueprint $table): void {
            $table->string('google_id', 64)->nullable()->unique()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('portal_accounts', function (Blueprint $table): void {
            $table->dropColumn('google_id');
        });
    }
};
