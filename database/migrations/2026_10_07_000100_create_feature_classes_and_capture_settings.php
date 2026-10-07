<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Area capture, stage 1: what a campaign may capture, and the catalogue of
 * feature classes an officer picks from when the ground has no buildings.
 *
 * Every existing campaign is left capturing buildings only, which is exactly
 * what it did before this migration ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            // Which capture tools the officers on this campaign are given.
            $table->jsonb('capture_modes')->default(DB::raw("'[\"buildings\"]'::jsonb"));
            // The smallest polygon worth recording. A farm plot of two square
            // metres is a digitising slip, not a farm.
            $table->decimal('min_mapping_unit_ha', 10, 4)->nullable();
            // The worst GPS fix a field capture may rest on. Null falls back to
            // the mandate's accuracy_threshold_m.
            $table->unsignedSmallInteger('field_max_accuracy_m')->nullable();
            // Share of desk-drawn features an officer is sent to ground-truth.
            $table->unsignedTinyInteger('verification_sample_pct')->default(10);
            // How far outside the campaign's ground a feature may run before it
            // is refused: a river bank drawn from 10 m imagery is not exact.
            $table->unsignedSmallInteger('boundary_tolerance_m')->default(25);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE campaigns
              ADD CONSTRAINT campaigns_capture_modes_known CHECK (
                jsonb_typeof(capture_modes) = 'array'
                AND jsonb_array_length(capture_modes) > 0
                AND capture_modes <@ '["buildings", "area_features"]'::jsonb
              ),
              ADD CONSTRAINT campaigns_verification_sample_pct_range CHECK (
                verification_sample_pct BETWEEN 0 AND 100
              ),
              ADD CONSTRAINT campaigns_min_mapping_unit_positive CHECK (
                min_mapping_unit_ha IS NULL OR min_mapping_unit_ha > 0
              )
        SQL);

        // A campaign_id of null is a global template; a campaign edits its own
        // copy, never the template, so one client's wording cannot leak into
        // the next campaign's catalogue.
        Schema::create('feature_classes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('key', 48);
            $table->string('label', 120);
            $table->string('geometry_type', 16);
            $table->jsonb('style')->nullable();
            // Classes sharing a group may not overlap: a piece of ground is
            // forest or farmland, not both. Null means it may overlap anything
            // (a river runs through a forest).
            $table->string('exclusivity_group', 32)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('copied_from_id')->nullable()->constrained('feature_classes')->restrictOnDelete();
            $table->timestamps();

            $table->index(['campaign_id', 'is_active', 'sort_order']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE feature_classes
              ADD CONSTRAINT feature_classes_geometry_type_known CHECK (
                geometry_type IN ('point', 'line', 'polygon')
              ),
              ADD CONSTRAINT feature_classes_key_shape CHECK (key ~ '^[a-z][a-z0-9_]*$')
        SQL);

        // One key per catalogue. Two classes answering to one name is an export
        // that silently merges them.
        DB::statement('CREATE UNIQUE INDEX feature_classes_one_template_per_key ON feature_classes (key) WHERE campaign_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX feature_classes_one_per_campaign_key ON feature_classes (campaign_id, key) WHERE campaign_id IS NOT NULL');

        // The attribute form a class asks for, frozen at each revision. A
        // feature points at the version it was captured against, so editing a
        // class after capture has started never changes what an old record
        // meant.
        Schema::create('feature_class_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feature_class_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('attribute_schema');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['feature_class_id', 'version']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE feature_class_versions
              ADD CONSTRAINT feature_class_versions_schema_is_array CHECK (
                jsonb_typeof(attribute_schema) = 'array'
              ),
              ADD CONSTRAINT feature_class_versions_positive CHECK (version > 0)
        SQL);

        // Immutable by the database, not by convention: a version that could be
        // edited is not a version.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feature_class_versions_are_immutable()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'feature_class_versions is append only: % is not permitted. Revise the class to write a new version.', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER feature_class_versions_no_update
                BEFORE UPDATE ON feature_class_versions
                FOR EACH ROW EXECUTE FUNCTION feature_class_versions_are_immutable();

            CREATE TRIGGER feature_class_versions_no_delete
                BEFORE DELETE ON feature_class_versions
                FOR EACH ROW EXECUTE FUNCTION feature_class_versions_are_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS feature_class_versions_no_update ON feature_class_versions;
            DROP TRIGGER IF EXISTS feature_class_versions_no_delete ON feature_class_versions;
            DROP FUNCTION IF EXISTS feature_class_versions_are_immutable();
        SQL);

        Schema::dropIfExists('feature_class_versions');
        Schema::dropIfExists('feature_classes');

        DB::statement('ALTER TABLE campaigns DROP CONSTRAINT IF EXISTS campaigns_capture_modes_known');
        DB::statement('ALTER TABLE campaigns DROP CONSTRAINT IF EXISTS campaigns_verification_sample_pct_range');
        DB::statement('ALTER TABLE campaigns DROP CONSTRAINT IF EXISTS campaigns_min_mapping_unit_positive');

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'capture_modes', 'min_mapping_unit_ha', 'field_max_accuracy_m',
                'verification_sample_pct', 'boundary_tolerance_m',
            ]);
        });
    }
};
