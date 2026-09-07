<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each rung of the ladder costs, as data rather than as code.
 *
 * A price change must never rewrite the history of what somebody was charged.
 * The row is resolved at order creation and stamped onto the order, so this
 * table is a lookup for new orders and never a source of truth for old ones.
 *
 * Zone is a multiplier held here rather than computed, for the same reason: two
 * zones because a customer can be told which one they are in and why, where a
 * distance formula produces a number nobody can argue with or predict.
 *
 * Superseded rather than edited. A price that changed in March and a price that
 * has always been wrong look identical in a table that updates in place, and
 * only one of those is a conversation you want to be able to have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_prices', function (Blueprint $table): void {
            $table->id();

            $table->string('tier', 32);
            $table->string('urgency', 16)->default('standard');
            $table->string('zone', 8)->default('A');

            // Minor units. Naira has kobo, and a price held as a float is a
            // price somebody eventually has to explain to an auditor.
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('NGN');

            // Working days, not calendar days. The definition is Nigerian
            // public holidays plus weekends, and it lives in code beside the
            // calendar rather than being implied by a number here.
            $table->unsignedSmallInteger('sla_working_days');

            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();

            $table->timestamps();

            $table->index(['tier', 'urgency', 'zone']);
        });

        // One live price per combination. Two would make order creation depend
        // on row order, which is how a customer gets charged the wrong amount
        // and nobody can say why.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX verification_prices_one_live_per_combination
            ON verification_prices (tier, urgency, zone)
            WHERE effective_to IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_prices');
    }
};
