<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a business tells investors about itself, and what it lets them read.
 *
 * An opportunity is the business's own act. Enumeration is not consent to be
 * shown to investors any more than it is consent to be shown to the public, so
 * there is no path by which an opportunity exists for a business that did not
 * publish one. One live opportunity per business: withdrawing keeps the row.
 *
 * Data-room documents are uploaded by the business and read only by an
 * organisation the business has granted. Grants are per organisation and are
 * decided by the business, never by us.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_id')->constrained();
            $table->foreignId('party_id')->constrained();

            $table->string('seeking', 32);
            $table->bigInteger('ticket_size_minor')->nullable();
            $table->string('currency', 3)->default('NGN');
            $table->text('use_of_funds')->nullable();
            $table->text('summary')->nullable();
            $table->smallInteger('operating_since')->nullable();
            $table->string('staff_on_site', 40)->nullable();
            $table->string('premises', 120)->nullable();

            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });

        DB::statement("ALTER TABLE opportunities ADD CONSTRAINT opportunities_status_check CHECK (status IN ('draft', 'published', 'withdrawn'))");
        DB::statement("ALTER TABLE opportunities ADD CONSTRAINT opportunities_seeking_check CHECK (seeking IN ('expansion_equity', 'growth_equity', 'asset_finance', 'working_capital', 'joint_venture', 'acquisition'))");
        DB::statement("CREATE UNIQUE INDEX opportunities_one_live_per_enterprise ON opportunities (enterprise_id) WHERE status <> 'withdrawn'");

        Schema::create('data_room_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained();
            $table->string('title', 160);
            $table->string('description', 240)->nullable();
            $table->string('disk', 32);
            $table->string('path');
            $table->string('mime', 120);
            $table->unsignedBigInteger('bytes');
            $table->foreignId('uploaded_by_party_id')->constrained('parties');
            $table->foreignId('uploaded_by_account_id')->constrained('portal_accounts');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['opportunity_id', 'status']);
        });

        Schema::create('data_room_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained();
            $table->foreignId('investor_organisation_id')->constrained();
            $table->string('status', 16)->default('requested');
            $table->foreignId('requested_by')->constrained('investor_users');
            $table->text('message')->nullable();
            $table->foreignId('decided_by_account_id')->nullable()->constrained('portal_accounts');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['opportunity_id', 'investor_organisation_id']);
        });

        DB::statement("ALTER TABLE data_room_grants ADD CONSTRAINT data_room_grants_status_check CHECK (status IN ('requested', 'granted', 'declined', 'revoked'))");

        // What an organisation keeps about an opportunity for itself: whether
        // it is watching, whether it has said it is interested, and its notes.
        // Private to the organisation, never shown to the business except the
        // fact of interest, which is the point of expressing it.
        Schema::create('investor_watchlist', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('investor_organisation_id')->constrained();
            $table->foreignId('opportunity_id')->constrained();
            $table->foreignId('added_by')->constrained('investor_users');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['investor_organisation_id', 'opportunity_id']);
        });

        Schema::create('investor_interests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('investor_organisation_id')->constrained();
            $table->foreignId('opportunity_id')->constrained();
            $table->foreignId('expressed_by')->constrained('investor_users');
            $table->text('message')->nullable();
            $table->timestamps();

            $table->unique(['investor_organisation_id', 'opportunity_id']);
        });

        Schema::create('investor_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('investor_organisation_id')->constrained();
            $table->foreignId('opportunity_id')->constrained();
            $table->text('body');
            $table->foreignId('updated_by')->constrained('investor_users');
            $table->timestamps();

            $table->unique(['investor_organisation_id', 'opportunity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_notes');
        Schema::dropIfExists('investor_interests');
        Schema::dropIfExists('investor_watchlist');
        Schema::dropIfExists('data_room_grants');
        Schema::dropIfExists('data_room_documents');
        Schema::dropIfExists('opportunities');
    }
};
