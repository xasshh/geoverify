<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Closes the gap the row level triggers leave open.
 *
 * TRUNCATE does not fire per row triggers, so an append only log guarded only by
 * BEFORE UPDATE and BEFORE DELETE can still be emptied in one statement. For the
 * one table whose entire value is that it was never edited, that is worth
 * blocking explicitly.
 *
 * Dropping the table is still possible, which is what migrate:fresh does. That is
 * a schema operation, visible in migration history, and a different thing from
 * quietly erasing the evidence while leaving the schema intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS verification_events_no_truncate ON verification_events;

            CREATE TRIGGER verification_events_no_truncate
                BEFORE TRUNCATE ON verification_events
                FOR EACH STATEMENT EXECUTE FUNCTION verification_events_are_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS verification_events_no_truncate ON verification_events');
    }
};
