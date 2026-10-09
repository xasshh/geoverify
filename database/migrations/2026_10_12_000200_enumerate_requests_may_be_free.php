<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A check may be free (decided 2026-10-09, while Enumerate launches).
 *
 * Free means both the price and the registry fee are nothing, so no ledger
 * movement is ever owed for it. A priced check keeps the old rule exactly: a
 * fee above nothing and no larger than the price.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE enumerate_requests
              DROP CONSTRAINT enumerate_requests_price_positive,
              DROP CONSTRAINT enumerate_requests_fee_within_price,
              ADD CONSTRAINT enumerate_requests_price_positive CHECK (price_minor >= 0),
              ADD CONSTRAINT enumerate_requests_fee_within_price CHECK (
                (price_minor = 0 AND registry_fee_minor = 0)
                OR (registry_fee_minor > 0 AND registry_fee_minor <= price_minor));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE enumerate_requests
              DROP CONSTRAINT enumerate_requests_price_positive,
              DROP CONSTRAINT enumerate_requests_fee_within_price,
              ADD CONSTRAINT enumerate_requests_price_positive CHECK (price_minor > 0),
              ADD CONSTRAINT enumerate_requests_fee_within_price CHECK (
                registry_fee_minor > 0 AND registry_fee_minor <= price_minor);
        SQL);
    }
};
