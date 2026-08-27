<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Control of a listing: who may manage it, and on what basis.
     *
     * Separate from `claims` because a claim is an event and control is a
     * state. Keeping them in one table would mean a revoked claim and a
     * withdrawn one and a superseded one all writing over the row that decides
     * who can act today, which is exactly the row that must never be ambiguous.
     */
    public function up(): void
    {
        Schema::create('party_businesses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();

            $table->string('relationship');

            // claim | self_registration. A listing built by its own owner and
            // one claimed from an officer's observation carry different weight,
            // and the difference has to survive in the record.
            $table->string('established_via');
            $table->timestamp('established_at');

            // Null for a self-registration, which has no claim behind it.
            $table->foreignId('claim_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status')->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            $table->timestamps();

            $table->index(['party_id', 'status']);
        });

        // The load-bearing one: at most one party controls a listing at a time.
        // Two parties both able to manage the same shop is the failure this
        // whole milestone exists to prevent, and it is a database rule rather
        // than an application one because application rules lose races.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX party_businesses_one_controller_per_enterprise
            ON party_businesses (enterprise_id)
            WHERE status = 'active'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('party_businesses');
    }
};
