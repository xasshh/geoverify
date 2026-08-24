<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The offline map pack an officer downloads before deployment.
 *
 * One row per build, never overwritten. A handset already holding v3 keeps
 * working from it while v4 exists, and a handset halfway through downloading v3
 * when v4 is published does not have the file pulled out from under it. The old
 * row is marked superseded rather than removed, which is the same rule the rest
 * of the register follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_packs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coverage_area_id')->constrained()->cascadeOnUpdate();

            // Versioned path, so publishing a new pack never overwrites a file a
            // handset may be reading.
            $table->string('path');
            $table->unsignedBigInteger('bytes');
            $table->string('checksum', 64);

            $table->unsignedSmallInteger('min_zoom');
            $table->unsignedSmallInteger('max_zoom');

            // What is actually inside, so the client can style only the layers
            // present and a supervisor can see a pack built without roads.
            $table->jsonb('layer_counts');

            $table->decimal('west', 9, 6);
            $table->decimal('south', 9, 6);
            $table->decimal('east', 9, 6);
            $table->decimal('north', 9, 6);

            $table->foreignId('built_by')->nullable()->constrained('users');
            $table->timestamp('built_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            // The current pack for a mandate is the one not yet superseded.
            $table->index(['coverage_area_id', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_packs');
    }
};
