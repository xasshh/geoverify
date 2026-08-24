<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each signal concluded about one capture, and what it cost.
 *
 * Kept rather than recomputed so the review queue can order 200 captures by
 * score without running ten signals over ten sessions of fixes, and so a
 * supervisor can be shown the same numbers the score was built from.
 *
 * Not append only, unlike verification_events. A signal row is a machine's
 * current reading, not a record of anything a person did: rescoring a capture
 * after a signal is corrected should replace the reading, not bury it under a
 * second one. What a supervisor decided, and what the score was when they
 * decided it, is written to verification_events, which is the log that matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observation_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('structure_observation_id')->constrained()->cascadeOnDelete();
            $table->string('signal', 48);
            // presence, identification or plausibility: which of the three
            // questions this flag belongs under on the review screen.
            $table->string('question', 24);
            $table->string('verdict', 12);
            $table->unsignedTinyInteger('weight');
            // What this reading actually took off the score, so the arithmetic
            // is auditable without rerunning it.
            $table->unsignedTinyInteger('deduction');
            // Written for a supervisor, not for a log.
            $table->string('message', 255);
            $table->jsonb('evidence')->nullable();
            $table->timestamps();

            $table->unique(['structure_observation_id', 'signal']);
            // The review queue reads flags by observation, worst first.
            $table->index(['structure_observation_id', 'deduction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_signals');
    }
};
