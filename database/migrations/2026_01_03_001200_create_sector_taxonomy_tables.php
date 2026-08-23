<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ISIC Rev 4, and the Nigerian trade names officers actually search for.
 *
 * The officer never sees or types a code. They search "POS operator" or
 * "vulcanizer" and the ISIC class is stored underneath, which is what makes the
 * register comparable internationally and reconcilable to NBS statistics while
 * keeping capture fast enough to do six hundred times a week.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isic_classes', function (Blueprint $table): void {
            $table->id();

            // section (A), division (47), group (471), class (4711).
            $table->string('level', 16);
            $table->string('code', 16)->unique();
            $table->string('parent_code', 16)->nullable();
            $table->string('name');
            $table->text('description')->nullable();

            $table->timestamps();

            $table->index('parent_code');
            $table->index('level');
        });

        Schema::create('trade_aliases', function (Blueprint $table): void {
            $table->id();

            // What the officer types. "Provisions store", "chemist", "mai shayi".
            $table->string('term');
            $table->string('isic_code', 16);

            // Higher wins when two aliases match. "POS operator" should beat a
            // generic "shop" for the same keystrokes.
            $table->unsignedSmallInteger('weight')->default(100);

            // en, ha, ig, yo. Officers work in the language of the street.
            $table->string('language', 8)->default('en');

            $table->timestamps();

            $table->unique(['term', 'isic_code']);
            $table->index('isic_code');
        });

        // Fuzzy search over the terms an officer types, which are frequently
        // misspelled and frequently abbreviated.
        DB::statement('CREATE INDEX trade_aliases_term_trgm ON trade_aliases USING GIN (term gin_trgm_ops)');
        DB::statement('CREATE INDEX isic_classes_name_trgm ON isic_classes USING GIN (name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_aliases');
        Schema::dropIfExists('isic_classes');
    }
};
