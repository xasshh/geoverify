<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody has paid us to go and look.
 *
 * The commercial spine of the phase. An order names a listing, a rung of the
 * ladder and an urgency, holds the money, produces a field visit, and closes
 * when a supervisor accepts the officer's work.
 *
 * Everything commercial is stamped, not referenced. The price, the currency,
 * the SLA and the zone are copied from verification_prices at creation, so a
 * price change next quarter cannot rewrite what this customer agreed to. The
 * same discipline coverage_areas already applies to a mandate boundary.
 *
 * A negative finding is a completed verification and is billable. If the officer
 * attends and the business is not there, the work was performed and the report
 * is delivered, so `outcome` is kept apart from `status`: what we found and how
 * far the order got are different questions, and collapsing them would make an
 * honest negative look like a failure to deliver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_orders', function (Blueprint $table): void {
            $table->id();

            // Human readable, because it goes on an invoice and gets read down
            // a phone: GV-2026-000123.
            $table->string('reference', 32)->unique();

            $table->foreignId('party_id')->constrained();
            $table->foreignId('enterprise_id')->constrained();

            // The building an officer will stand in front of. Copied at
            // creation rather than followed through the enterprise, because a
            // listing that moves must not silently redirect a paid visit.
            $table->foreignId('structure_id')->constrained();

            // The person who acted, kept apart from the party who owes for it.
            $table->foreignId('ordered_by')->constrained('portal_accounts');

            $table->string('tier', 32);
            $table->string('urgency', 16)->default('standard');
            $table->string('zone', 8);

            // Stamped from verification_prices, never joined to it.
            $table->foreignId('price_id')->nullable()->constrained('verification_prices');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('NGN');
            $table->unsignedSmallInteger('sla_working_days');

            $table->string('status', 32)->default('awaiting_payment');

            // What the visit found, once there has been one. Null until then,
            // and independent of status: a negative finding is a completed
            // order.
            $table->string('outcome', 32)->nullable();

            $table->timestamp('paid_at')->nullable();

            // The end of the SLA, in working days from payment. Computed once
            // and stored, so the promise a customer was given cannot drift when
            // the holiday calendar is corrected later.
            $table->date('due_by')->nullable();

            $table->foreignId('assignment_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index(['party_id', 'status']);
            $table->index(['status', 'due_by']);
            $table->index('enterprise_id');
        });

        // One live order per listing per tier. Somebody buying the same
        // verification twice while the first is still out is paying twice for
        // one visit, and it is our job to notice rather than theirs.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX verification_orders_one_live_per_tier
            ON verification_orders (enterprise_id, tier)
            WHERE status IN ('awaiting_payment', 'paid', 'assigned', 'in_progress', 'submitted')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_orders');
    }
};
