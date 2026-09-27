<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Investors, on their own guard.
 *
 * The fourth guard, for the reason the client has the third: an investor is
 * not staff, not a party and not the commissioning body, and a session that
 * could be any of them is a session every middleware has to interrogate.
 *
 * KYC belongs to the organisation rather than to the person, because it is the
 * fund, bank or angel syndicate that is vetted. The decision records who made
 * it and when; what was checked is a human judgement the system does not make.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_organisations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('kind', 24);
            $table->string('country', 2)->default('NG');
            $table->string('website')->nullable();

            $table->string('kyc_status', 16)->default('pending');
            $table->foreignId('kyc_decided_by')->nullable()->constrained('users');
            $table->timestamp('kyc_decided_at')->nullable();
            $table->text('kyc_note')->nullable();

            $table->timestamps();

            $table->index('kyc_status');
        });

        DB::statement("ALTER TABLE investor_organisations ADD CONSTRAINT investor_organisations_kyc_status_check CHECK (kyc_status IN ('pending', 'verified', 'suspended'))");

        Schema::create('investor_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('investor_organisation_id')->constrained();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('title', 80)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamp('last_signed_in_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['investor_organisation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_users');
        Schema::dropIfExists('investor_organisations');
    }
};
