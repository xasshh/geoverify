<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Area capture, stage 4: features arriving in bulk, and officers sent to check
 * them.
 *
 * area_feature_batches records each import (a client's file, or the land
 * cover seed) with what it produced and what it refused. Revisions point at
 * the batch they came from, so a bad import can be found and withdrawn.
 *
 * area_verification_tasks is the sample of desk-drawn and imported features
 * an officer is sent to ground-truth: one open task per feature at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_feature_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coverage_area_id')->constrained()->restrictOnDelete();
            // import (a file somebody sent) or landcover (ESA WorldCover).
            $table->string('kind', 24);
            $table->string('status', 24)->default('queued');
            $table->string('source_name')->nullable();
            $table->string('source_path')->nullable();
            $table->jsonb('mapping')->nullable();
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('refused_count')->default(0);
            // A sample of what was refused and why, for the person who asked.
            $table->jsonb('refusals')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['coverage_area_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE area_feature_batches
              ADD CONSTRAINT area_feature_batches_kind_known CHECK (kind IN ('import', 'landcover')),
              ADD CONSTRAINT area_feature_batches_status_known CHECK (status IN ('queued', 'processing', 'done', 'failed'))
        SQL);

        // The append-only trigger on revisions compares whole rows, so a new
        // column is added with a default and never changed afterwards.
        Schema::table('area_feature_revisions', function (Blueprint $table): void {
            $table->foreignId('area_feature_batch_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('area_verification_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('area_feature_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            // open, done, cancelled.
            $table->string('status', 24)->default('open');
            // verified, reclassified, rejected, needs_revisit.
            $table->string('outcome', 24)->nullable();
            $table->foreignId('resolved_revision_id')->nullable()->constrained('area_feature_revisions')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['assigned_to', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE area_verification_tasks
              ADD CONSTRAINT area_verification_tasks_status_known CHECK (status IN ('open', 'done', 'cancelled')),
              ADD CONSTRAINT area_verification_tasks_outcome_known CHECK (
                outcome IS NULL OR outcome IN ('verified', 'reclassified', 'rejected', 'needs_revisit')
              )
        SQL);

        DB::statement('CREATE UNIQUE INDEX area_verification_tasks_one_open ON area_verification_tasks (area_feature_id) WHERE status = \'open\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('area_verification_tasks');
        Schema::table('area_feature_revisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('area_feature_batch_id');
        });
        Schema::dropIfExists('area_feature_batches');
    }
};
