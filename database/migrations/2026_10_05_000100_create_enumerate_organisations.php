<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enumerate E5: organisations, their teams, bulk verification and projects.
 *
 * An organisation is a set of the same portal accounts (decision 9), each with
 * a role, and a wallet of its own. It can sign up and fund that wallet at once;
 * bulk verification and projects wait for an admin to approve it (decision
 * 10), as investor organisations do.
 *
 * A member is invited by phone number. The row waits with the number until an
 * account with that number accepts it, so an invitation can go to somebody who
 * has never signed in here. Revoked, never deleted.
 *
 * A wallet has exactly one owner, a person or an organisation. A request
 * records the organisation it was bought for and, when it came in a CSV, the
 * batch, so the Verifications page can roll a batch up.
 *
 * A project is the organisation's commission for custom enumeration. It is
 * scoped by the account manager and runs as a campaign (CAMPAIGNS.md): the
 * link is the project's campaign_id, and what the organisation sees of it is
 * what AssembleCampaignDossier gives a client, never the commercials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enumerate_organisations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('rc_number', 32)->nullable();
            $table->string('contact_email', 180)->nullable();
            // pending, approved, suspended.
            $table->string('status', 16)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('account_manager_id')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('portal_accounts');
            $table->timestamps();

            $table->index('status');
        });

        DB::statement("ALTER TABLE enumerate_organisations ADD CONSTRAINT enumerate_organisations_status_check CHECK (status IN ('pending', 'approved', 'suspended'))");

        Schema::create('enumerate_organisation_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained('enumerate_organisations');
            $table->string('phone', 20);
            $table->foreignId('portal_account_id')->nullable()->constrained();
            // admin, project_lead, requester, viewer.
            $table->string('role', 16);
            $table->foreignId('invited_by')->nullable()->constrained('portal_accounts');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['portal_account_id', 'revoked_at']);
            $table->index('phone');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE enumerate_organisation_members
              ADD CONSTRAINT enumerate_members_role_check CHECK (role IN ('admin', 'project_lead', 'requester', 'viewer')),
              ADD CONSTRAINT enumerate_members_accepted_has_account CHECK (accepted_at IS NULL OR portal_account_id IS NOT NULL);

            CREATE UNIQUE INDEX enumerate_members_one_live_per_phone
              ON enumerate_organisation_members (organisation_id, phone) WHERE revoked_at IS NULL;
        SQL);

        Schema::table('enumerate_wallets', function (Blueprint $table): void {
            $table->foreignId('organisation_id')->nullable()->unique()->after('portal_account_id')->constrained('enumerate_organisations');
        });

        DB::statement('ALTER TABLE enumerate_wallets ADD CONSTRAINT enumerate_wallets_one_owner CHECK (num_nonnulls(portal_account_id, organisation_id) = 1)');

        Schema::create('enumerate_batches', function (Blueprint $table): void {
            $table->id();
            // BLK-20261005-7H2QK.
            $table->string('reference', 32)->unique();
            $table->foreignId('organisation_id')->constrained('enumerate_organisations');
            $table->foreignId('created_by')->constrained('portal_accounts');
            $table->unsignedTinyInteger('tier');
            $table->unsignedSmallInteger('monitoring_days')->nullable();
            $table->unsignedInteger('rows_total');
            $table->unsignedInteger('rows_placed');
            $table->unsignedInteger('rows_refused');
            $table->bigInteger('total_minor');
            $table->timestamps();
        });

        Schema::create('enumerate_batch_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enumerate_batch_id')->constrained();
            $table->unsignedInteger('line');
            $table->string('name', 200)->nullable();
            $table->string('rc_number', 40)->nullable();
            $table->string('tin', 40)->nullable();
            $table->string('address', 300)->nullable();
            // placed, refused. A refused row says why and costs nothing.
            $table->string('outcome', 8);
            $table->string('reason', 200)->nullable();
            $table->foreignId('enumerate_request_id')->nullable()->constrained();
            $table->timestamp('created_at')->nullable();

            $table->index(['enumerate_batch_id', 'line']);
        });

        DB::statement("ALTER TABLE enumerate_batch_rows ADD CONSTRAINT enumerate_batch_rows_outcome_check CHECK ((outcome = 'placed' AND enumerate_request_id IS NOT NULL) OR (outcome = 'refused' AND reason IS NOT NULL))");

        Schema::table('enumerate_requests', function (Blueprint $table): void {
            $table->foreignId('organisation_id')->nullable()->after('wallet_id')->constrained('enumerate_organisations');
            $table->foreignId('enumerate_batch_id')->nullable()->after('organisation_id')->constrained();
            $table->index('organisation_id');
        });

        Schema::create('enumerate_projects', function (Blueprint $table): void {
            $table->id();
            // PRJ-20261005-4KD8M.
            $table->string('reference', 32)->unique();
            $table->foreignId('organisation_id')->constrained('enumerate_organisations');
            $table->foreignId('requested_by')->constrained('portal_accounts');
            $table->string('name', 160);
            // What is being enumerated: vendors, schools, boreholes.
            $table->string('subject', 120);
            $table->string('area', 300);
            $table->unsignedInteger('target_records')->nullable();
            $table->date('wanted_by')->nullable();
            // The fields the organisation asked for, as a specification for
            // the account manager: [{label, type}]. Not a form the field app
            // reads (decision 3); the campaign's declared schema is.
            $table->jsonb('fields');
            $table->text('notes')->nullable();
            // requested, scoping, live, closed, declined.
            $table->string('status', 16)->default('requested');
            $table->foreignId('campaign_id')->nullable()->constrained();
            $table->timestamps();

            $table->index(['organisation_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE enumerate_projects
              ADD CONSTRAINT enumerate_projects_status_check CHECK (status IN ('requested', 'scoping', 'live', 'closed', 'declined')),
              ADD CONSTRAINT enumerate_projects_live_has_campaign CHECK (status NOT IN ('live', 'closed') OR campaign_id IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('enumerate_projects');

        Schema::table('enumerate_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('enumerate_batch_id');
            $table->dropConstrainedForeignId('organisation_id');
        });

        Schema::dropIfExists('enumerate_batch_rows');
        Schema::dropIfExists('enumerate_batches');

        DB::statement('ALTER TABLE enumerate_wallets DROP CONSTRAINT IF EXISTS enumerate_wallets_one_owner');

        Schema::table('enumerate_wallets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organisation_id');
        });

        Schema::dropIfExists('enumerate_organisation_members');
        Schema::dropIfExists('enumerate_organisations');
    }
};
