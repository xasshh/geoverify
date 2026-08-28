<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An observation somebody made about their own business.
     *
     * Kept on the observation rather than inferred from the enterprise,
     * because the two come apart the moment an officer visits a
     * self-registered business: that record will hold a party's original
     * account and an officer's later one, and which is which has to be
     * readable from the row rather than from the record it hangs off.
     *
     * Exactly one of the two, enforced. An observation with neither has no
     * author, and one with both is claiming an officer stood behind something
     * a party typed, which is the misrepresentation this whole phase is
     * arranged to prevent.
     */
    public function up(): void
    {
        Schema::table('enterprise_observations', function (Blueprint $table) {
            $table->foreignId('recorded_by_party_id')->nullable()->constrained('parties');
        });

        DB::statement('ALTER TABLE enterprise_observations ALTER COLUMN captured_by DROP NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE enterprise_observations ADD CONSTRAINT enterprise_observations_one_author CHECK (
                (captured_by IS NOT NULL AND recorded_by_party_id IS NULL)
             OR (captured_by IS NULL AND recorded_by_party_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE enterprise_observations DROP CONSTRAINT IF EXISTS enterprise_observations_one_author');

        Schema::table('enterprise_observations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by_party_id');
        });
    }
};
