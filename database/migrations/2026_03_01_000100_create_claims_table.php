<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();

            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();

            // The person who acted, kept apart from the party they acted for. A
            // party outlives the individuals who hold access to it, and when a
            // claim is questioned two years on, "who submitted this" is a
            // different question from "who does it belong to".
            $table->foreignId('submitted_by')->constrained('portal_accounts');

            $table->string('relationship');
            $table->timestamp('asserted_at');

            // What was offered and what each signal returned. Written once per
            // signal as it resolves, never recomputed: a decision has to be
            // reconstructible from what was true at the time, not from what the
            // register says today.
            $table->jsonb('evidence')->default('{}');

            $table->string('status')->default('submitted');

            // Null while it sits in review. Non-null names the rule that
            // settled it, so an auto-approval can always be told from a human
            // one without joining anywhere.
            $table->string('decision')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['enterprise_id', 'status']);
            $table->index(['party_id', 'status']);
            // The review queue reads this: oldest live claim first.
            $table->index(['status', 'asserted_at']);
        });

        // One live claim per party per listing. Resubmitting after a rejection
        // is allowed and is sometimes the right thing to do (documents get
        // found); submitting twice while the first is still open is noise in
        // the review queue.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX claims_one_live_per_party_enterprise
            ON claims (party_id, enterprise_id)
            WHERE status IN ('submitted', 'disputed')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
