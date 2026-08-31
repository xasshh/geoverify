<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a listing may be published, and it may not by default.
 *
 * Enumeration is not consent to publication. An officer walked up to a shop and
 * recorded what was there; nobody asked the owner whether they wanted to be in a
 * public directory, and the answer cannot be assumed from the fact that the
 * shop has a door onto the street.
 *
 * So `private` is the default and the only way out of it is a party opting in
 * deliberately, on a record they have proved they control. `withheld` is the
 * third state and it is not the same as `private`: private means nobody has
 * asked, withheld means somebody was asked and said no, and a later feature
 * that quietly re-asks the second group would be a different kind of wrong.
 *
 * Nothing publishes off this column yet. The Discovery Portal does not exist
 * here and is not being built; what this does is make sure that when it can be
 * built, the answer to "may we show this" is already recorded rather than
 * reconstructed from silence years later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprises', function (Blueprint $table): void {
            $table->string('publication_state', 24)->default('private')->after('status');
            $table->timestamp('publication_decided_at')->nullable()->after('publication_state');
        });

        Schema::table('enterprises', function (Blueprint $table): void {
            // Read by any future publication query, which will always be
            // filtering for the one state that permits it.
            $table->index('publication_state');
        });
    }

    public function down(): void
    {
        Schema::table('enterprises', function (Blueprint $table): void {
            $table->dropIndex(['publication_state']);
            $table->dropColumn(['publication_state', 'publication_decided_at']);
        });
    }
};
