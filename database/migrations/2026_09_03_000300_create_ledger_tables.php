<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Double entry, append only, and never adjusted in place.
 *
 * Money that moves through this platform is recorded the way money is recorded
 * anywhere it has to be defended: every movement is two entries that sum to
 * zero, and a mistake is corrected by writing the opposite movement rather than
 * by editing the first one. An adjustable ledger is a spreadsheet.
 *
 * A customer's payment lands in a liability account, because until an officer
 * has attended and a supervisor has accepted the work, that money is theirs and
 * not ours. Completion moves it to income. A refund moves it back out.
 *
 * On the word this deliberately does not use: funds are held and released, and
 * the account is named for what it is, an obligation to the customer. The
 * regulated term is not used anywhere, including in state and account names,
 * and there is a test that greps for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();

            // Stable, readable, and what a reconciliation report is grouped by.
            $table->string('code', 32)->unique();
            $table->string('name');

            // asset, liability, income, expense. Which side increases an
            // account is a property of its type, so the type has to be here
            // rather than inferred by whoever is reading.
            $table->string('type', 16);
            $table->string('currency', 3)->default('NGN');

            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();

            // The movement this entry is half of. Two or more entries share
            // one, and their signed amounts sum to zero.
            $table->uuid('transaction_uuid');

            $table->foreignId('ledger_account_id')->constrained();

            /*
             * Signed minor units. One column rather than a debit and a credit
             * column, because two columns permit a row with both filled in or
             * neither, and the balance check then has to trust that nobody did
             * that. A sign cannot be in two states at once.
             */
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('NGN');

            $table->foreignId('verification_order_id')->nullable()
                ->constrained()->nullOnDelete();

            // What this movement was, in the language of the business rather
            // than of the schema: payment_received, work_completed, refunded.
            $table->string('reason', 48);
            $table->text('narrative')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('transaction_uuid');
            $table->index(['ledger_account_id', 'occurred_at']);
            $table->index('verification_order_id');
        });

        // Append only, enforced by the database, the same way verification_events
        // is. Convention is not enough for a table whose entire value is that it
        // was never edited, and this one holds money.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_entries_are_append_only()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'ledger_entries is append only: % is not permitted. Correct a mistake by posting the opposite movement.', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_entries_no_update
                BEFORE UPDATE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_entries_are_append_only();

            CREATE TRIGGER ledger_entries_no_delete
                BEFORE DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_entries_are_append_only();

            CREATE TRIGGER ledger_entries_no_truncate
                BEFORE TRUNCATE ON ledger_entries
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_entries_are_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update ON ledger_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_delete ON ledger_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_truncate ON ledger_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_entries_are_append_only()');

        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};
