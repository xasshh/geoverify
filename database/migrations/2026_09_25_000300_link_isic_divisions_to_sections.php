<?php

declare(strict_types=1);

use App\Console\Commands\TaxonomyLoadCommand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Divisions were loaded without their section, so nothing could be rolled up
 * past the division. The loader now sets it; this sets it on a taxonomy that
 * was loaded before. A no-op on an empty table.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (TaxonomyLoadCommand::SECTION_RANGES as $section => [$from, $to]) {
            DB::table('isic_classes')
                ->where('level', 'division')
                ->whereNull('parent_code')
                ->whereRaw('code ~ \'^[0-9]{2}$\'')
                ->whereRaw('code::int BETWEEN ? AND ?', [$from, $to])
                ->update(['parent_code' => $section]);
        }
    }

    public function down(): void
    {
        DB::table('isic_classes')->where('level', 'division')->update(['parent_code' => null]);
    }
};
