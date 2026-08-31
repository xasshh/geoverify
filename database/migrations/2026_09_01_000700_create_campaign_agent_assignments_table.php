<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who is deployed on this campaign.
 *
 * Not a duplicate of `assignments`, which is a different question one level
 * down: that one hands an officer a specific H3 cell to walk, and the field
 * client, the map packs and the review queue are all built on it. This says who
 * is on the exercise at all, which is what a client means by "how many agents
 * do you have on this" and what a supervisor means before they start handing
 * out cells.
 *
 * `assignments` stays exactly as it is. Nothing in the field platform reads this
 * table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_agent_assignments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();

            // The mandate within the campaign this person is working, where
            // that has been decided. Null is ordinary: somebody deployed to the
            // exercise before the ground is carved up.
            $table->foreignId('coverage_area_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();

            // Stood down, never deleted. An officer who left mid-campaign still
            // captured what they captured, and the roster has to explain who was
            // out when those records were made.
            $table->string('status', 24)->default('active');

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        // One live deployment per person per campaign. Standing somebody down
        // and bringing them back is ordinary and allowed; two open rows for one
        // person is a roster that double counts them.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX campaign_agent_assignments_one_live_per_user
            ON campaign_agent_assignments (campaign_id, user_id)
            WHERE unassigned_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_agent_assignments');
    }
};
