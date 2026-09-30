<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enumerate E1: a person pays from a wallet to have any business checked.
 *
 * The subject of a request is a CAC record, not a row of our register: most
 * businesses somebody wants checked are ones no officer has reached, so the
 * request carries what the registry said about it and nothing is written to
 * `enterprises` on a stranger's say-so.
 *
 * The wallet is prepaid credit, spent on requests and never withdrawn (see the
 * flow document, decision 7). Its balance is a sum over the ledger like every
 * other balance here: `wallet_id` on an entry says whose money it moved, beside
 * the one subject the entry is about.
 *
 * Registry checks are what the provider answered, one row per lookup, and a
 * re-run appends. The supervisor's reading of them is on the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enumerate_wallets', function (Blueprint $table): void {
            $table->id();
            // An individual's wallet. Organisations (E5) get a column of
            // their own rather than a type flag, so a check can hold one owner.
            $table->foreignId('portal_account_id')->nullable()->unique()->constrained();
            $table->timestamps();
        });

        Schema::create('wallet_fundings', function (Blueprint $table): void {
            $table->id();
            // FND-20260923-0081: the provider's reference, readable off a receipt.
            $table->string('reference', 32)->unique();
            $table->foreignId('wallet_id')->constrained('enumerate_wallets');
            $table->foreignId('portal_account_id')->constrained();
            $table->bigInteger('amount_minor');
            $table->string('channel', 16);
            // pending until the signed webhook says paid; failed is the
            // provider's word, never ours.
            $table->string('status', 16)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE wallet_fundings
              ADD CONSTRAINT wallet_fundings_amount_positive CHECK (amount_minor > 0),
              ADD CONSTRAINT wallet_fundings_channel_check CHECK (channel IN ('card', 'bank_transfer')),
              ADD CONSTRAINT wallet_fundings_status_check CHECK (status IN ('pending', 'paid', 'failed'))
        SQL);

        Schema::create('enumerate_prices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('tier');
            // Tier 3 is priced per monitoring period; the others have none.
            $table->unsignedSmallInteger('days')->nullable();
            $table->bigInteger('amount_minor');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE enumerate_prices
              ADD CONSTRAINT enumerate_prices_tier_check CHECK (tier BETWEEN 1 AND 3),
              ADD CONSTRAINT enumerate_prices_days_check CHECK (
                (tier = 3 AND days IN (7, 14, 30)) OR (tier < 3 AND days IS NULL)),
              ADD CONSTRAINT enumerate_prices_amount_positive CHECK (amount_minor > 0)
        SQL);

        // One live price per tier and period.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX enumerate_prices_one_live
            ON enumerate_prices (tier, COALESCE(days, 0)) WHERE effective_to IS NULL
        SQL);

        $now = now();
        DB::table('enumerate_prices')->insert(array_map(static fn (array $p): array => [
            'tier' => $p[0], 'days' => $p[1], 'amount_minor' => $p[2] * 100,
            'effective_from' => $now, 'created_at' => $now, 'updated_at' => $now,
        ], [
            [1, null, 1_500],
            [2, null, 5_000],
            // The mockup prices 30 days only. 7 and 14 are starting figures
            // to be confirmed (decision 6), changed here rather than in code.
            [3, 7, 6_000],
            [3, 14, 9_000],
            [3, 30, 12_000],
        ]));

        Schema::create('enumerate_requests', function (Blueprint $table): void {
            $table->id();
            // VRF-20260917-KQ83P2.
            $table->string('reference', 32)->unique();
            $table->foreignId('wallet_id')->constrained('enumerate_wallets');
            $table->foreignId('requested_by')->constrained('portal_accounts');

            $table->unsignedTinyInteger('tier');
            $table->unsignedSmallInteger('monitoring_days')->nullable();
            $table->bigInteger('price_minor');
            // The Tier 1 price when this was sold: what the desk check earns
            // on its own. A Tier 2 or 3 that fails at the desk keeps this and
            // returns the rest to the wallet, because no officer went.
            $table->bigInteger('registry_fee_minor');

            // The subject, as the registry named it when it was picked.
            $table->string('subject_name', 200);
            $table->string('rc_number', 32);
            // COMPANY, BUSINESS_NAME and the rest, as CAC types them.
            $table->string('company_type', 40);
            $table->string('registered_address', 300)->nullable();

            // paid, registry_check, passed, failed, awaiting_agent, ... The
            // visit states arrive with E2; the check below grows with them.
            $table->string('status', 24)->default('paid');
            $table->timestamp('paid_at');

            // The supervisor's reading of the registry answers.
            $table->string('registry_outcome', 8)->nullable();
            $table->string('registry_reason', 300)->nullable();
            $table->foreignId('desk_checked_by')->nullable()->constrained('users');
            $table->timestamp('desk_checked_at')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'paid_at']);
            $table->index(['wallet_id', 'created_at']);
            $table->index('rc_number');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE enumerate_requests
              ADD CONSTRAINT enumerate_requests_tier_check CHECK (tier BETWEEN 1 AND 3),
              ADD CONSTRAINT enumerate_requests_days_check CHECK (
                (tier = 3 AND monitoring_days IN (7, 14, 30)) OR (tier < 3 AND monitoring_days IS NULL)),
              ADD CONSTRAINT enumerate_requests_price_positive CHECK (price_minor > 0),
              ADD CONSTRAINT enumerate_requests_fee_within_price CHECK (
                registry_fee_minor > 0 AND registry_fee_minor <= price_minor),
              ADD CONSTRAINT enumerate_requests_status_check CHECK (status IN (
                'paid', 'registry_check', 'passed', 'failed', 'awaiting_agent')),
              ADD CONSTRAINT enumerate_requests_outcome_check CHECK (
                registry_outcome IS NULL OR registry_outcome IN ('passed', 'failed')),
              ADD CONSTRAINT enumerate_requests_desk_whole CHECK (
                (registry_outcome IS NULL AND desk_checked_at IS NULL AND desk_checked_by IS NULL)
                OR (registry_outcome IS NOT NULL AND desk_checked_at IS NOT NULL AND desk_checked_by IS NOT NULL)),
              ADD CONSTRAINT enumerate_requests_failure_has_reason CHECK (
                registry_outcome IS DISTINCT FROM 'failed' OR registry_reason IS NOT NULL)
        SQL);

        Schema::create('registry_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enumerate_request_id')->constrained();
            // cac or tin. NIN arrives only with a person's own consent, and
            // never as a stored number (see CLAUDE.md).
            $table->string('kind', 8);
            $table->string('provider', 16);
            // matched: the register answered and agrees; mismatched: it
            // answered and disagrees; not_found; unavailable: it did not answer.
            $table->string('outcome', 16);
            // What the register said, reduced to the fields the report prints.
            // Directors by name and role only: CAC's phone numbers and genders
            // for them are dropped before this is written.
            $table->jsonb('facts')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamp('checked_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['enumerate_request_id', 'checked_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE registry_checks
              ADD CONSTRAINT registry_checks_kind_check CHECK (kind IN ('cac', 'tin')),
              ADD CONSTRAINT registry_checks_outcome_check CHECK (
                outcome IN ('matched', 'mismatched', 'not_found', 'unavailable'))
        SQL);

        // The ledger learns two more subjects, and whose wallet a movement
        // touched. wallet_id is an owner rather than a subject, so it stays out
        // of the one-subject rule: spending from a wallet on a request is about
        // the request, and is still that wallet's money.
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->foreignId('enumerate_request_id')->nullable()->after('payout_id')->constrained();
            $table->foreignId('wallet_funding_id')->nullable()->after('enumerate_request_id')->constrained();
            $table->foreignId('wallet_id')->nullable()->after('wallet_funding_id')->constrained('enumerate_wallets');

            $table->index('enumerate_request_id');
            $table->index('wallet_funding_id');
            $table->index('wallet_id');
        });

        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_one_subject');
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_one_subject
              CHECK (num_nonnulls(verification_order_id, purchase_order_id, payout_id, enumerate_request_id, wallet_funding_id) <= 1)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_one_subject');

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('wallet_id');
            $table->dropConstrainedForeignId('wallet_funding_id');
            $table->dropConstrainedForeignId('enumerate_request_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_one_subject
              CHECK (num_nonnulls(verification_order_id, purchase_order_id, payout_id) <= 1)
        SQL);

        Schema::dropIfExists('registry_checks');
        Schema::dropIfExists('enumerate_requests');
        Schema::dropIfExists('enumerate_prices');
        Schema::dropIfExists('wallet_fundings');
        Schema::dropIfExists('enumerate_wallets');
    }
};
