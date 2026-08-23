<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The append only audit log. This log is the product.
 *
 * Nothing in this system is hard deleted. A status change, an identity check, an
 * export, a supervisor's acceptance: each appends a row here with the evidence
 * that produced it. What a client buys is not the current state of a record, it
 * is the ability to show how that record came to say what it says.
 *
 * Enforced by trigger rather than by convention, because a convention holds only
 * until someone writes an UPDATE in a hurry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_events', function (Blueprint $table): void {
            $table->id();

            $table->morphs('subject');

            $table->string('event', 64);

            // user, system or external. A confidence deduction is the system's
            // doing; an acceptance is a person's.
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();

            // What the event was based on: the signals, the scores, the response
            // reference. Read by a supervisor and by an auditor, so it holds the
            // reasoning, not just the outcome.
            $table->jsonb('evidence')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
        });

        // Append only, enforced by the database. Convention is not enough for the
        // one table whose value is that it was never edited.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION verification_events_are_append_only()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'verification_events is append only: % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER verification_events_no_update
                BEFORE UPDATE ON verification_events
                FOR EACH ROW EXECUTE FUNCTION verification_events_are_append_only();

            CREATE TRIGGER verification_events_no_delete
                BEFORE DELETE ON verification_events
                FOR EACH ROW EXECUTE FUNCTION verification_events_are_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS verification_events_no_update ON verification_events');
        DB::unprepared('DROP TRIGGER IF EXISTS verification_events_no_delete ON verification_events');
        DB::unprepared('DROP FUNCTION IF EXISTS verification_events_are_append_only()');
        Schema::dropIfExists('verification_events');
    }
};
