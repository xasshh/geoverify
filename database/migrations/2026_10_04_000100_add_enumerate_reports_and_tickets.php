<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enumerate E4: the score and report, and support.
 *
 * The score and finding are stamped when a request finishes, the way an SLA
 * date is stamped at payment: a report somebody printed in October must say
 * the same thing when its QR code is scanned in March, whatever we change
 * about scoring meanwhile. Before it finishes the score is read live and the
 * report says "interim".
 *
 * The report token is what the printed QR code carries: forty random
 * characters, minted on the first download, and the whole authorisation of
 * the public check (like a certificate's). Nothing derivable from the
 * reference printed beside it.
 *
 * A ticket is a thread: the requester and the support desk write into it and
 * nothing written is edited. A refund is an admin's ruling on a ticket and is
 * posted to the ledger like any other movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enumerate_requests', function (Blueprint $table): void {
            $table->unsignedTinyInteger('score')->nullable()->after('registry_reason');
            $table->string('finding', 60)->nullable()->after('score');
            $table->string('report_token', 64)->nullable()->unique()->after('finding');
        });

        DB::statement('ALTER TABLE enumerate_requests ADD CONSTRAINT enumerate_requests_score_range CHECK (score IS NULL OR score BETWEEN 0 AND 100)');

        Schema::create('enumerate_tickets', function (Blueprint $table): void {
            $table->id();
            // TKT-20260926-9YQDJ.
            $table->string('reference', 32)->unique();
            $table->foreignId('portal_account_id')->constrained();
            $table->foreignId('enumerate_request_id')->nullable()->constrained();
            // report_quality, registry_result, payments, general.
            $table->string('category', 24);
            $table->string('subject', 160);
            // open, in_review, resolved.
            $table->string('status', 16)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['portal_account_id', 'updated_at']);
            $table->index(['status', 'updated_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE enumerate_tickets
              ADD CONSTRAINT enumerate_tickets_category_check CHECK (category IN ('report_quality', 'registry_result', 'payments', 'general')),
              ADD CONSTRAINT enumerate_tickets_status_check CHECK (status IN ('open', 'in_review', 'resolved'))
        SQL);

        Schema::create('enumerate_ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enumerate_ticket_id')->constrained();
            // Exactly one author: the requester, or somebody on the desk.
            $table->foreignId('portal_account_id')->nullable()->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->text('body');
            // A refund ruled on this ticket, in kobo, when the message is one.
            $table->bigInteger('refund_minor')->nullable();
            $table->timestamp('created_at');

            $table->index(['enumerate_ticket_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE enumerate_ticket_messages
              ADD CONSTRAINT enumerate_ticket_messages_one_author CHECK (num_nonnulls(portal_account_id, user_id) = 1),
              ADD CONSTRAINT enumerate_ticket_messages_body_length CHECK (char_length(body) BETWEEN 1 AND 4000),
              ADD CONSTRAINT enumerate_ticket_messages_refund_by_staff CHECK (
                refund_minor IS NULL OR (refund_minor > 0 AND user_id IS NOT NULL));

            -- What was said stays said.
            CREATE OR REPLACE FUNCTION enumerate_ticket_messages_are_append_only()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'enumerate_ticket_messages is append only: % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER enumerate_ticket_messages_no_change
                BEFORE UPDATE OR DELETE ON enumerate_ticket_messages
                FOR EACH ROW EXECUTE FUNCTION enumerate_ticket_messages_are_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS enumerate_ticket_messages_no_change ON enumerate_ticket_messages;
            DROP FUNCTION IF EXISTS enumerate_ticket_messages_are_append_only();
        SQL);

        Schema::dropIfExists('enumerate_ticket_messages');
        Schema::dropIfExists('enumerate_tickets');

        DB::statement('ALTER TABLE enumerate_requests DROP CONSTRAINT IF EXISTS enumerate_requests_score_range');

        Schema::table('enumerate_requests', function (Blueprint $table): void {
            $table->dropColumn(['score', 'finding', 'report_token']);
        });
    }
};
