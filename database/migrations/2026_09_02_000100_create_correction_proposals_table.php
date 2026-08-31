<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A party says the register has something wrong about their business.
 *
 * A proposal, never an edit. What an officer observed on a given morning is a
 * record of that morning, and a party who could rewrite it would be able to
 * turn a field observation into a self-description while keeping the
 * credibility the field visit gave it. That is the single most valuable thing
 * this platform sells, and it is the reason the listing page has no edit form.
 *
 * So the original observation is never touched. An accepted proposal writes a
 * new observation authored by the party, which M4 already made possible, and
 * moves the projection on `enterprises`. March stays exactly as March was
 * recorded, beside the party's account of it.
 *
 * `current_value` is stamped at proposal time rather than read at decision
 * time. A supervisor deciding in October has to see what the party was looking
 * at in August, not what the register happens to say the morning they open the
 * queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correction_proposals', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();

            // The person who acted, kept apart from the party they acted for. A
            // party outlives the individuals who hold access to it, and when a
            // correction is questioned two years on, "who submitted this" is a
            // different question from "whose business is it".
            $table->foreignId('proposed_by')->constrained('portal_accounts');

            // Which field, from a closed list. Free text here would let a party
            // propose a change to a column that does not exist, or to one they
            // have no business touching, and the review screen would have to
            // decide what to do about it at the worst possible moment.
            $table->string('field', 48);

            $table->text('current_value')->nullable();
            $table->text('proposed_value')->nullable();

            // Why. Required, because a correction with no account of itself is
            // a supervisor guessing, and the guess falls on the officer.
            $table->text('reason');

            // A document supporting the claim: a CAC certificate, a tenancy, a
            // photograph of new signage. Optional, and never sufficient on its
            // own, but it is what turns most of these from a judgement call
            // into a reading.
            $table->foreignId('evidence_media_id')->nullable()
                ->constrained('media')->nullOnDelete();

            $table->string('status', 24)->default('submitted');

            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            // The review queue reads this: oldest live proposal first.
            $table->index(['status', 'created_at']);
            $table->index(['enterprise_id', 'status']);
            $table->index(['party_id', 'status']);
        });

        // One live proposal per field per listing. A party who spots a second
        // mistake should raise it; a party who submits the same field twice
        // while the first is open is noise in somebody's morning.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX correction_proposals_one_live_per_field
            ON correction_proposals (enterprise_id, field)
            WHERE status = 'submitted'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('correction_proposals');
    }
};
