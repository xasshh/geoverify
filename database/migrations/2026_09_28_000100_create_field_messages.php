<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a supervisor and an officer say to each other in the field.
 *
 * Every message belongs to one officer's thread: the conversation between that
 * officer and whoever supervises them. A broadcast is written once per
 * recipient, sharing a broadcast_uuid, so each officer's copy carries its own
 * read receipt and an officer's inbox is one query on one column.
 *
 * Kept apart from verification_events on purpose. The event log is the record
 * of what happened to the register; this is conversation, and "on my way" is
 * not a fact about a building. The structured kinds (a returned record, a cell
 * assigned) point at the thing they are about, so the inbox can open it.
 *
 * An officer's message may be written on a handset with no signal and sent
 * hours later, so it carries the client's own uuid (a replay is one row) and
 * the time it was written as well as the time it arrived. Nothing here is
 * deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();

            // Whose thread this is. Always an officer.
            $table->foreignId('officer_id')->constrained('users');

            // Who said it. Null for a message the system wrote on somebody's
            // behalf is not allowed: every structured message has an author.
            $table->foreignId('sender_id')->constrained('users');

            // to_officer or from_officer: which way it travelled in the thread.
            $table->string('direction', 16);

            // text, returned_record, cell_assigned, broadcast.
            $table->string('kind', 24)->default('text');
            $table->text('body');

            $table->foreignId('observation_id')->nullable()->constrained('structure_observations');
            $table->foreignId('assignment_id')->nullable()->constrained();
            $table->uuid('broadcast_uuid')->nullable();

            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('read_at')->nullable();

            // When it was written, which for an officer offline can be long
            // before it reached us.
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['officer_id', 'id']);
            $table->index(['officer_id', 'read_at']);
            $table->index('broadcast_uuid');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE field_messages
              ADD CONSTRAINT field_messages_direction_check CHECK (direction IN ('to_officer', 'from_officer')),
              ADD CONSTRAINT field_messages_kind_check CHECK (kind IN ('text', 'returned_record', 'cell_assigned', 'broadcast')),
              ADD CONSTRAINT field_messages_body_length CHECK (char_length(body) BETWEEN 1 AND 1000)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('field_messages');
    }
};
