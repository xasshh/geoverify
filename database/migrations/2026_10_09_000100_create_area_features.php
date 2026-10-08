<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Area capture, stage 3: features of the land and their revisions.
 *
 * The same shape as structures and their observations. area_features is the
 * thing (this river, this farm); area_feature_revisions is every time somebody
 * drew or checked it, never edited. A desk-digitised shape and the boundary an
 * officer walked both survive, and the feature points at whichever is current.
 *
 * Nothing here touches the building tables. grid_cells gains a separate count,
 * so cell completion for buildings is computed exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_features', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('coverage_area_id')->constrained()->restrictOnDelete();
            $table->foreignId('feature_class_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('current_revision_id')->nullable();
            // unverified, verified, rejected, needs_revisit.
            $table->string('verification_status', 24)->default('unverified');
            // live, or withdrawn: never deleted.
            $table->string('status', 24)->default('live');
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->timestamps();

            $table->index(['coverage_area_id', 'feature_class_id', 'status']);
            $table->index(['campaign_id', 'verification_status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE area_features
              ADD CONSTRAINT area_features_verification_known CHECK (
                verification_status IN ('unverified', 'verified', 'rejected', 'needs_revisit')
              ),
              ADD CONSTRAINT area_features_status_known CHECK (status IN ('live', 'withdrawn'))
        SQL);

        Schema::create('area_feature_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('area_feature_id')->constrained()->restrictOnDelete();
            $table->foreignId('feature_class_version_id')->constrained()->restrictOnDelete();
            $table->jsonb('answers');
            // desk_digitised, field_walked, field_drawn, imported, field_verified.
            $table->string('capture_method', 24);
            $table->foreignId('basemap_layer_id')->nullable()->constrained()->nullOnDelete();
            $table->date('imagery_date')->nullable();
            $table->decimal('gps_accuracy_m', 8, 2)->nullable();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            // The walked boundary is this session's position fixes: the same
            // track the anti-spoofing signals already read for buildings.
            $table->foreignId('field_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('captured_by')->constrained('users');
            $table->timestamp('captured_at');
            // Area and length always computed by PostGIS over geography.
            $table->decimal('area_ha', 14, 4)->nullable();
            $table->decimal('length_m', 14, 2)->nullable();
            $table->boolean('geometry_repaired')->default(false);
            $table->decimal('repair_area_change_pct', 7, 2)->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['area_feature_id', 'captured_at']);
        });

        DB::statement('ALTER TABLE area_feature_revisions ADD COLUMN geom geometry(Geometry, 4326) NOT NULL');
        DB::statement('CREATE INDEX area_feature_revisions_geom_gist ON area_feature_revisions USING GIST (geom)');
        DB::statement(<<<'SQL'
            ALTER TABLE area_feature_revisions
              ADD CONSTRAINT area_feature_revisions_geom_kind CHECK (
                GeometryType(geom) IN ('POINT', 'LINESTRING', 'POLYGON', 'MULTIPOLYGON')
              ),
              ADD CONSTRAINT area_feature_revisions_method_known CHECK (
                capture_method IN ('desk_digitised', 'field_walked', 'field_drawn', 'imported', 'field_verified')
              )
        SQL);

        // Append only, like verification_events and the ledger: a revision
        // that could be edited would make the history a story.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION area_feature_revisions_are_append_only()
            RETURNS trigger AS $$
            BEGIN
                -- The score is written after the fact by the scoring job, and
                -- is the one column allowed to change.
                IF TG_OP = 'UPDATE'
                   AND (to_jsonb(NEW) - 'confidence_score' - 'updated_at')
                       = (to_jsonb(OLD) - 'confidence_score' - 'updated_at') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'area_feature_revisions is append only: % is not permitted. Write a new revision.', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER area_feature_revisions_no_update
                BEFORE UPDATE ON area_feature_revisions
                FOR EACH ROW EXECUTE FUNCTION area_feature_revisions_are_append_only();

            CREATE TRIGGER area_feature_revisions_no_delete
                BEFORE DELETE ON area_feature_revisions
                FOR EACH ROW EXECUTE FUNCTION area_feature_revisions_are_append_only();
        SQL);

        Schema::table('area_features', function (Blueprint $table): void {
            $table->foreign('current_revision_id')->references('id')->on('area_feature_revisions')->restrictOnDelete();
        });

        // The cells a feature touches, so coverage maps and cell progress work
        // for land as they do for buildings.
        Schema::create('area_feature_cells', function (Blueprint $table): void {
            $table->foreignId('area_feature_id')->constrained()->restrictOnDelete();
            $table->foreignId('grid_cell_id')->constrained()->restrictOnDelete();
            $table->primary(['area_feature_id', 'grid_cell_id']);
            $table->index('grid_cell_id');
        });

        Schema::table('grid_cells', function (Blueprint $table): void {
            $table->unsignedInteger('area_features_count')->default(0);
        });

        // The direction the camera faced, for a photograph of open ground
        // where "which way" is most of what it shows.
        Schema::table('media', function (Blueprint $table): void {
            $table->decimal('bearing_deg', 5, 1)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media', fn (Blueprint $t) => $t->dropColumn('bearing_deg'));
        Schema::table('grid_cells', fn (Blueprint $t) => $t->dropColumn('area_features_count'));
        Schema::dropIfExists('area_feature_cells');
        Schema::table('area_features', fn (Blueprint $t) => $t->dropForeign(['current_revision_id']));
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS area_feature_revisions_no_update ON area_feature_revisions;
            DROP TRIGGER IF EXISTS area_feature_revisions_no_delete ON area_feature_revisions;
            DROP FUNCTION IF EXISTS area_feature_revisions_are_append_only();
        SQL);
        Schema::dropIfExists('area_feature_revisions');
        Schema::dropIfExists('area_features');
    }
};
