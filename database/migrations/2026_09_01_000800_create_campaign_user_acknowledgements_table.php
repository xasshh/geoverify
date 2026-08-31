<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who has read the brief, and when.
 *
 * Polymorphic because two different kinds of person are shown it and they live
 * on different guards: staff in `users`, a client's administrator in
 * `client_users`. The same morph pattern verification_events already uses, so
 * there is one way of pointing at "somebody, of some kind" in this codebase
 * rather than two.
 *
 * Kept rather than cleared when a brief is revised. The campaign carries
 * `definition_revised_at` and an acknowledgement older than it simply stops
 * counting, which re-shows the modal without destroying the record of who read
 * which version. Deleting rows here would answer "has this person seen it" at
 * the cost of "did they ever", and the second is the one an auditor asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_user_acknowledgements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();

            $table->string('acknowledged_by_type');
            $table->unsignedBigInteger('acknowledged_by_id');

            $table->timestamp('acknowledged_at');

            $table->timestamps();

            $table->unique(
                ['campaign_id', 'acknowledged_by_type', 'acknowledged_by_id'],
                'campaign_acknowledgements_one_per_person',
            );
            $table->index(['acknowledged_by_type', 'acknowledged_by_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_user_acknowledgements');
    }
};
