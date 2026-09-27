<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A verification order may be paid for by an investor organisation.
 *
 * The visit, the price, the SLA, the webhook, the ledger and the certificate
 * are the same product whoever pays, so this is a second payer on the same
 * table rather than a second table. Exactly one payer per order, and exactly
 * one person who placed it, each held by a check constraint: an order with no
 * payer is money nobody can be refunded, and an order with two is a dispute
 * waiting to happen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_orders', function (Blueprint $table): void {
            $table->foreignId('investor_organisation_id')->nullable()->after('party_id')->constrained();
            $table->foreignId('ordered_by_investor')->nullable()->after('ordered_by')->constrained('investor_users');
            $table->index(['investor_organisation_id', 'status']);
        });

        DB::statement('ALTER TABLE verification_orders ALTER COLUMN party_id DROP NOT NULL');
        DB::statement('ALTER TABLE verification_orders ALTER COLUMN ordered_by DROP NOT NULL');

        DB::statement('ALTER TABLE verification_orders ADD CONSTRAINT verification_orders_one_payer CHECK (num_nonnulls(party_id, investor_organisation_id) = 1)');
        DB::statement('ALTER TABLE verification_orders ADD CONSTRAINT verification_orders_one_orderer CHECK (num_nonnulls(ordered_by, ordered_by_investor) = 1)');
        DB::statement('ALTER TABLE verification_orders ADD CONSTRAINT verification_orders_orderer_matches_payer CHECK ((party_id IS NULL) = (ordered_by IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE verification_orders DROP CONSTRAINT verification_orders_orderer_matches_payer');
        DB::statement('ALTER TABLE verification_orders DROP CONSTRAINT verification_orders_one_orderer');
        DB::statement('ALTER TABLE verification_orders DROP CONSTRAINT verification_orders_one_payer');

        Schema::table('verification_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ordered_by_investor');
            $table->dropConstrainedForeignId('investor_organisation_id');
        });

        DB::statement('ALTER TABLE verification_orders ALTER COLUMN party_id SET NOT NULL');
        DB::statement('ALTER TABLE verification_orders ALTER COLUMN ordered_by SET NOT NULL');
    }
};
