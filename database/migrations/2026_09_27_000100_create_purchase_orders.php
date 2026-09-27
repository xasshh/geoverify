<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M2: somebody buys something a business listed, and the money is held.
 *
 * An order is a buyer, one seller and the lines they agreed on. Prices are
 * copied onto the lines when the order is placed, so a merchant editing a price
 * afterwards changes their catalogue and not what a buyer already agreed to
 * pay. The seller is the party that controlled the business at that moment and
 * stays that party: a change of control later does not move a sale.
 *
 * The buyer's money goes where a verification customer's does, through the
 * signed webhook and into the ledger, and it leaves by one of two roads: to the
 * merchant's balance when the buyer confirms delivery, or back to the buyer on
 * a ruling. Nothing here stores a card, and the bank account a merchant is paid
 * into is kept as the provider's recipient code and its last four digits.
 *
 * Never the regulated word, in a column, a state or a comment a customer might
 * one day read: money is held, and released.
 */
return new class extends Migration
{
    public function up(): void
    {
        // GV-10482: short enough to read down a phone to a rider, and a
        // different shape from a verification order's GV-2026-000123, so the
        // webhook can never mistake one for the other.
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 24)->unique();

            $table->foreignId('enterprise_id')->constrained();
            $table->foreignId('seller_party_id')->constrained('parties');
            $table->foreignId('buyer_account_id')->constrained('portal_accounts');

            $table->string('status', 24)->default('awaiting_payment');

            // none, inspection, site_visit. The last two are charged here and
            // worked in M3.
            $table->string('protection', 16)->default('none');

            // What the buyer chose to pay with. The provider is told to offer
            // only that, and it is shown on the timeline.
            $table->string('channel', 16);

            // Kobo. The total is stored and checked, never recomputed on read.
            $table->bigInteger('items_minor');
            $table->bigInteger('delivery_minor')->default(0);
            $table->bigInteger('service_fee_minor')->default(0);
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('NGN');

            // Stamped at release from configuration, so a rate change later
            // does not rewrite what a merchant was paid.
            $table->bigInteger('commission_minor')->nullable();

            // Where it goes. A buyer's own name and address, given to the
            // merchant to deliver to, which is the purpose they were given for.
            $table->string('delivery_name', 120);
            $table->string('delivery_phone', 24);
            $table->string('delivery_address', 240);
            $table->string('delivery_note', 280)->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('released_by', 16)->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->string('dispute_reason', 500)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['seller_party_id', 'status']);
            $table->index(['buyer_account_id', 'created_at']);
            $table->index(['status', 'dispatched_at']);
        });

        // Owned by the table, so dropping the table drops it: migrate:fresh
        // removes tables, and a free-standing sequence would survive it.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS purchase_order_number_seq START WITH 10001 OWNED BY purchase_orders.id');

        DB::statement(<<<'SQL'
            ALTER TABLE purchase_orders
              ADD CONSTRAINT purchase_orders_status_check CHECK (status IN
                ('awaiting_payment', 'held', 'dispatched', 'released', 'disputed', 'refunded', 'cancelled')),
              ADD CONSTRAINT purchase_orders_protection_check CHECK (protection IN ('none', 'inspection', 'site_visit')),
              ADD CONSTRAINT purchase_orders_channel_check CHECK (channel IN ('card', 'bank_transfer', 'ussd')),
              ADD CONSTRAINT purchase_orders_amounts_check CHECK (
                items_minor > 0 AND delivery_minor >= 0 AND service_fee_minor >= 0
                AND amount_minor = items_minor + delivery_minor + service_fee_minor),
              ADD CONSTRAINT purchase_orders_commission_check CHECK (
                commission_minor IS NULL OR (commission_minor >= 0 AND commission_minor <= items_minor))
        SQL);

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained();
            $table->foreignId('product_id')->constrained();

            // As listed when the buyer agreed, not as listed now.
            $table->string('name', 120);
            $table->string('unit', 60)->nullable();
            $table->bigInteger('unit_price_minor');
            $table->unsignedInteger('quantity');
            $table->bigInteger('line_minor');

            $table->timestamps();

            $table->unique(['purchase_order_id', 'product_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE purchase_order_items
              ADD CONSTRAINT purchase_order_items_line_check CHECK (
                unit_price_minor > 0 AND quantity BETWEEN 1 AND 999
                AND line_minor = unit_price_minor * quantity)
        SQL);

        // Where a merchant is paid. One live account per party; replacing it
        // retires the old row rather than editing it, so a payout always points
        // at the account it was actually sent to.
        Schema::create('payout_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('party_id')->constrained();
            $table->string('bank_code', 12);
            $table->string('bank_name', 120);
            $table->string('account_last4', 4);
            $table->string('account_name', 160);
            $table->string('recipient_code', 64);
            $table->string('status', 16)->default('active');
            $table->foreignId('added_by')->constrained('portal_accounts');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE payout_accounts ADD CONSTRAINT payout_accounts_status_check CHECK (status IN ('active', 'retired'))");
        DB::statement("CREATE UNIQUE INDEX payout_accounts_one_live ON payout_accounts (party_id) WHERE status = 'active'");

        Schema::create('payouts', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('party_id')->constrained();
            $table->foreignId('payout_account_id')->constrained();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('NGN');

            // requested: reserved out of the balance, transfer asked for.
            // paid: the provider's signed webhook says it arrived.
            // returned: it failed or was reversed, and the balance has it back.
            $table->string('status', 16)->default('requested');
            $table->string('transfer_code', 64)->nullable();
            $table->string('failure_reason', 280)->nullable();
            $table->foreignId('requested_by')->constrained('portal_accounts');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['party_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payouts
              ADD CONSTRAINT payouts_status_check CHECK (status IN ('requested', 'paid', 'returned')),
              ADD CONSTRAINT payouts_amount_check CHECK (amount_minor > 0)
        SQL);

        // The ledger learns two more things a movement can be about. At most
        // one of them per entry, so no movement is claimed by two stories.
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->foreignId('purchase_order_id')->nullable()->after('verification_order_id')->constrained();
            $table->foreignId('payout_id')->nullable()->after('purchase_order_id')->constrained();

            $table->index('purchase_order_id');
            $table->index('payout_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_one_subject
              CHECK (num_nonnulls(verification_order_id, purchase_order_id, payout_id) <= 1)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_one_subject');

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payout_id');
            $table->dropConstrainedForeignId('purchase_order_id');
        });

        Schema::dropIfExists('payouts');
        Schema::dropIfExists('payout_accounts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        DB::statement('DROP SEQUENCE IF EXISTS purchase_order_number_seq');
    }
};
