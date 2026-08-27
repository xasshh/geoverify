<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A second party says the listing is theirs.
     *
     * This is an ordinary event, not an error. The register is built from
     * doorstep observation: an officer writes down the number on the shutter,
     * and that number belongs to the landlord, or to the son who minds the
     * stall on Saturdays, or to the tenant before last. Answering the real
     * owner with "already claimed" and no way forward would strand them, so a
     * challenge opens a path instead of closing one.
     *
     * The dispute points at the incumbent's control record rather than at the
     * incumbent's claim, because control does not always come from a claim: a
     * business that registered itself has no claim behind it and would
     * otherwise be unchallengeable.
     */
    public function up(): void
    {
        Schema::create('claim_disputes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();

            // Who holds it now, and the claim challenging them.
            $table->foreignId('incumbent_party_business_id')->constrained('party_businesses');
            $table->foreignId('challenger_claim_id')->unique()->constrained('claims');

            $table->timestamp('opened_at');
            $table->text('grounds')->nullable();

            // upheld_incumbent | transferred_to_challenger | withdrawn
            $table->string('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            $table->index(['enterprise_id', 'resolution']);
        });

        // The review queue reads open disputes oldest first, and there are far
        // fewer of these than resolved ones.
        DB::statement(<<<'SQL'
            CREATE INDEX claim_disputes_open
            ON claim_disputes (opened_at)
            WHERE resolution IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_disputes');
    }
};
