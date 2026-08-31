<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which storey a business trades on.
 *
 * `structures.floors` has always counted the storeys. What was missing was where
 * inside that stack each business sits, because `unit_label` is free text: "G03",
 * "Shop 4" and "First Floor Left" are all real captures and none of them can be
 * counted. A register that knows a building has three storeys and fourteen units
 * still cannot say what is on the second one, which is the question anybody
 * standing in front of the building actually asks.
 *
 * Signed, and deliberately. Ground is 0, first is 1, a basement is -1. Ground as
 * zero is what makes the arithmetic work: a storey index is directly comparable
 * to `structures.floors`, so a business recorded on the fourth floor of a two
 * storey building reads as a contradiction on the review screen instead of
 * sitting in the register looking ordinary.
 *
 * Nullable, and that is a real answer rather than a gap. An officer who did not
 * go inside does not know, and a default of "ground" would be the system
 * inventing a fact nobody observed. Unplaced businesses are shown as unplaced.
 *
 * Placement, not observation. This sits beside `unit_label` on `enterprises` for
 * the same reason that does: where a business sits in a building is part of
 * where it is, not what it looked like on a Tuesday.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprises', function (Blueprint $table): void {
            $table->smallInteger('floor')->nullable()->after('unit_label');
        });
    }

    public function down(): void
    {
        Schema::table('enterprises', function (Blueprint $table): void {
            $table->dropColumn('floor');
        });
    }
};
