<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mandate belongs to a campaign.
 *
 * Additive and nullable, which is what keeps this safe. A mandate is ground the
 * field platform already owns: grid cells, map packs, assignments and every
 * structure captured inside it all key off coverage_area_id, and the shipped
 * field client knows nothing about campaigns. Nothing here changes for it.
 *
 * A separate campaign_areas table was the other option and it would have meant
 * two notions of "area" in one system: one with a boundary, a grid and offline
 * tiles, and one with a target and a state name. The offline pack would still
 * have been built from the first, so the second would have been a label that
 * drifted. Coverage areas are the areas. Campaigns group them.
 *
 * Null stays legal on purpose: every mandate captured before campaigns existed
 * has one, and the backfill gives them a home rather than the column pretending
 * they were always part of one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coverage_areas', function (Blueprint $table): void {
            $table->foreignId('campaign_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();

            // Per mandate share of the campaign's total. Nullable because a
            // campaign can carry a target without anybody having split it yet.
            $table->unsignedInteger('target_record_count')->nullable();

            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::table('coverage_areas', function (Blueprint $table): void {
            $table->dropForeign(['campaign_id']);
            $table->dropColumn(['campaign_id', 'target_record_count']);
        });
    }
};
