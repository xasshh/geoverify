<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may act for a party, and in what capacity.
 *
 * A company with an owner and two staff is the normal case rather than the
 * advanced one, so it is built now instead of bolted on when the first company
 * asks. An owner can invite; a manager can order verification and propose
 * corrections; a viewer can only read.
 *
 * An invitation exists as a row before it is accepted, which is what lets an
 * owner add a colleague by phone number before that colleague has ever opened
 * the portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_users', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_account_id')->constrained()->cascadeOnDelete();

            // owner | manager | viewer
            $table->string('role', 16);

            $table->foreignId('invited_by')->nullable()
                ->constrained('portal_accounts')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            // Revoked rather than deleted, so who could act for a party at a
            // given moment stays answerable after the fact.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->unique(['party_id', 'portal_account_id']);
            $table->index(['portal_account_id', 'revoked_at']);
        });

        // A party always has exactly one owner able to act. Losing the last
        // owner would strand the party's listings and its money with nobody
        // able to answer for either.
        // The role is written inline rather than bound: Postgres cannot infer
        // the type of a parameter inside an index predicate, and this is a
        // fixed literal rather than input.
        DB::statement(
            "CREATE UNIQUE INDEX party_users_one_live_owner
             ON party_users (party_id) WHERE role = 'owner' AND revoked_at IS NULL",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('party_users');
    }
};
