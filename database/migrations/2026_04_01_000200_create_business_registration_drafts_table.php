<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Progress through a registration that is not finished yet.
     *
     * Kept because the audience for this form is on a handset, on mobile data,
     * often standing in the shop the form is about. A call comes in, the tab
     * dies, the connection drops on the third question. Losing four answered
     * questions is how somebody decides not to bother, and this register is
     * worth precisely what its coverage is worth.
     *
     * The payload is what the person typed and nothing derived from it. In
     * particular no ward, LGA or state: those are resolved server side from the
     * point at submission, every time, so a draft that sat for a week cannot
     * carry a stale or forged hierarchy into the register.
     */
    public function up(): void
    {
        Schema::create('business_registration_drafts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_account_id')->constrained()->cascadeOnDelete();

            $table->string('step')->default('name');
            $table->jsonb('payload')->default('{}');

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('enterprise_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
        });

        // One unfinished registration per party. Two half-filled forms for the
        // same business is a way to end up with two listings, and the person
        // who abandoned the first one will not remember it exists.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX registration_drafts_one_live_per_party
            ON business_registration_drafts (party_id)
            WHERE completed_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_registration_drafts');
    }
};
