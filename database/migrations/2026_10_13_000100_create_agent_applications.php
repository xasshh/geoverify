<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Applications to become a field agent (the onboarding data of 2026-10-07).
 *
 * An applicant is a member of the public with a portal account, so the
 * application hangs off `portal_accounts` and never off `users`: staff do not
 * self register. An administrator approving it is what creates the officer,
 * through CreateStaffMember, and `user_id` records which one.
 *
 * NIN and BVN are not here. They go to `identity_claims` as a keyed hash and
 * the last four digits. The guarantor and the next of kin are somebody else's
 * personal data, so those columns are encrypted at rest. Documents live on the
 * private disk, encrypted before they are written, and a replaced document is
 * marked replaced rather than removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_applications', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->foreignId('portal_account_id')->unique()->constrained('portal_accounts');
            $table->string('status', 24)->default('draft');

            // 1.1 Identity and contact.
            $table->string('surname', 80)->nullable();
            $table->string('first_name', 80)->nullable();
            $table->string('middle_name', 80)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('sex', 8)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->text('residential_address')->nullable();
            $table->foreignId('residence_state_id')->nullable()->constrained('admin_boundaries');
            $table->foreignId('residence_lga_id')->nullable()->constrained('admin_boundaries');
            $table->foreignId('origin_state_id')->nullable()->constrained('admin_boundaries');
            $table->foreignId('origin_lga_id')->nullable()->constrained('admin_boundaries');
            $table->jsonb('languages')->default(DB::raw("'[]'::jsonb"));

            // 1.2 Vetting. Free text for the people around the applicant, encrypted.
            $table->string('qualification', 32)->nullable();
            $table->jsonb('experience')->default(DB::raw("'[]'::jsonb"));
            $table->unsignedSmallInteger('experience_years')->nullable();
            $table->text('guarantor')->nullable();
            $table->text('next_of_kin')->nullable();

            // 1.3 The phone they will work on, as the browser reported it.
            $table->jsonb('device')->nullable();

            // 1.5 Each agreement with the moment it was given.
            $table->jsonb('consents')->default(DB::raw("'{}'::jsonb"));
            $table->string('signed_name', 160)->nullable();

            // The decision, and 1.4 as the administrator recorded it.
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->jsonb('capture_modes')->default(DB::raw("'[]'::jsonb"));
            $table->unsignedSmallInteger('assessment_score')->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users');

            $table->timestamps();
            $table->index(['status', 'submitted_at']);
        });

        DB::statement("ALTER TABLE agent_applications ADD CONSTRAINT agent_applications_status_check CHECK (status IN ('draft', 'submitted', 'changes_requested', 'approved', 'rejected'))");
        DB::statement("ALTER TABLE agent_applications ADD CONSTRAINT agent_applications_sex_check CHECK (sex IS NULL OR sex IN ('female', 'male'))");
        // An approved application always names the officer it became.
        DB::statement("ALTER TABLE agent_applications ADD CONSTRAINT agent_applications_approved_user CHECK ((status = 'approved') = (user_id IS NOT NULL))");

        Schema::create('agent_application_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_application_id')->constrained('agent_applications');
            $table->string('kind', 32);
            $table->string('label', 64)->nullable();
            $table->string('path');
            $table->string('mime', 64);
            $table->unsignedInteger('size_bytes');
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();
            $table->index(['agent_application_id', 'kind']);
        });

        DB::statement("ALTER TABLE agent_application_files ADD CONSTRAINT agent_application_files_kind_check CHECK (kind IN ('passport_photo', 'government_id', 'qualification_certificate', 'guarantor_id', 'guarantor_form', 'police_certificate'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_application_files');
        Schema::dropIfExists('agent_applications');
    }
};
