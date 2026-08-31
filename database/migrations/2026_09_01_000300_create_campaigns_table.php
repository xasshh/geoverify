<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A commissioned enumeration exercise.
 *
 * The layer above a mandate, not a replacement for one. A mandate
 * (coverage_areas) is ground: a boundary, a grid, map packs, assignments and
 * every structure captured inside it. A campaign is why that ground is being
 * walked, for whom, against what target and to what timetable. One campaign
 * holds several mandates, and a client holds several campaigns over time.
 *
 * No soft deletes, deliberately. Nothing in this system is hard deleted and the
 * house pattern is a status plus an appended event, not a nullable column that
 * hides a row from ordinary queries. `archived` is the end state, and the
 * transitions that reach it are written to verification_events like everything
 * else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('client_organisation_id')->constrained()->cascadeOnDelete();

            // NRS-MIN-2026-01. Client, subject, year, sequence within that year.
            // Read down a phone and written on an invoice, so it is short,
            // upper case and unique.
            $table->string('code', 32)->unique();

            $table->string('name');

            // What is being enumerated: mining companies, hair extension
            // traders, private clinics. Free text on purpose. Every attempt to
            // fix this list in advance meets a client who commissions the
            // twelfth thing, and a wrong taxonomy is worse than none.
            $table->string('subject_type');

            // The dossier. Long form, shown in the About tab and shortened for
            // the intro modal, and the reason a field officer knows what they
            // have been sent to do.
            $table->text('about')->nullable();
            $table->text('objective')->nullable();

            $table->string('status', 32)->default('draft');

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->unsignedInteger('target_record_count')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            /*
             * When the definition last changed in a way people need to see
             * again. Acknowledgements older than this stop counting, so a
             * revised brief re-shows its modal without anybody deleting rows,
             * and the record of who acknowledged what and when survives the
             * revision instead of being cleared by it.
             */
            $table->timestamp('definition_revised_at')->nullable();

            $table->timestamps();

            $table->index(['client_organisation_id', 'status']);
            // The client's campaign list, and the super admin's global one.
            $table->index(['status', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
