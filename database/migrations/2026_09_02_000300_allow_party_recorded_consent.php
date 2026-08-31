<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consent a party gave for itself, rather than one an officer read out.
 *
 * The same shape M4 gave enterprise_observations, and for the same reason: a
 * consent record has to say who took it, and the two kinds come apart the
 * moment a self-registered business is later visited. That record will hold a
 * party's own agreement and an officer's doorstep script, and which is which
 * has to be readable from the row.
 *
 * Exactly one author, enforced by a check rather than by convention. A consent
 * with neither has nobody standing behind it, and one with both is claiming an
 * officer witnessed something a party ticked on a phone, which is precisely the
 * misrepresentation this phase is arranged to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_records', function (Blueprint $table): void {
            $table->foreignId('recorded_by_party_id')->nullable()->constrained('parties');
        });

        DB::statement('ALTER TABLE consent_records ALTER COLUMN recorded_by DROP NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE consent_records ADD CONSTRAINT consent_records_one_author CHECK (
                (recorded_by IS NOT NULL AND recorded_by_party_id IS NULL)
             OR (recorded_by IS NULL AND recorded_by_party_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE consent_records DROP CONSTRAINT IF EXISTS consent_records_one_author');

        Schema::table('consent_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recorded_by_party_id');
        });
    }
};
