<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this campaign is collecting, declared.
 *
 * A declaration in this pass, not yet a collector. Phase 1 gathers against a
 * fixed, typed schema: structures and enterprises with their own enums, a
 * hand-built capture screen, a typed offline store and a sync contract that
 * validates against those enums on both the live and the offline path. Making
 * the field client render arbitrary fields touches every one of those, and it
 * is not the thing a client is asking for when they ask what is being collected.
 *
 * What this buys now is that a client can see, exactly and in writing, the
 * schema they commissioned, and a super admin can state it before an officer is
 * deployed. The collection side is a separate decision, taken with the sync
 * contract open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_fields', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();

            $table->string('label');

            // The machine name a collected value would land under. Stable once
            // set: it is what any later export column is named after.
            $table->string('key', 64);

            $table->string('type', 24);

            // Only meaningful for the select types. jsonb rather than text so a
            // malformed option list fails on write instead of on render.
            $table->jsonb('options')->nullable();

            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('help_text')->nullable();

            $table->timestamps();

            // One key per campaign. Two fields answering to the same name is a
            // schema that cannot be exported without silently dropping one.
            $table->unique(['campaign_id', 'key']);
            $table->index(['campaign_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_fields');
    }
};
