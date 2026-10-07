<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Area capture, stage 2: ground drawn from a file, and imagery to see it by.
 *
 * Two additions, neither of which changes what exists:
 *
 *  - A mandate can now come from an uploaded boundary (a forest reserve, a
 *    project site) as well as from an LGA. Every existing mandate is marked as
 *    coming from its LGA, which is where it came from.
 *  - basemap_layers holds satellite imagery for a mandate, built into a raster
 *    PMTiles archive the officer carries offline. It sits beside map_packs, not
 *    inside it: a handset already in the field expects one vector pack per
 *    mandate from /api/field/packs, and that answer does not change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coverage_areas', function (Blueprint $table): void {
            $table->string('boundary_source', 24)->default('admin_boundary');
            // The file the boundary was read from, kept so the ground a client
            // contracted can always be traced to what they sent.
            $table->string('boundary_file')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE coverage_areas
              ADD CONSTRAINT coverage_areas_boundary_source_known CHECK (
                boundary_source IN ('admin_boundary', 'uploaded')
              )
        SQL);

        Schema::create('basemap_layers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coverage_area_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            // sentinel2 (built from the free Copernicus archive) or upload (a
            // client's own drone or commercial GeoTIFF, later).
            $table->string('source', 24);
            $table->string('status', 24)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('stage', 64)->nullable();
            $table->text('error')->nullable();
            // What the image is of, and when: the officer is shown the date,
            // because a field cleared since then is not a mistake.
            $table->jsonb('scenes')->nullable();
            $table->date('captured_from')->nullable();
            $table->date('captured_to')->nullable();
            $table->decimal('cloud_pct', 5, 2)->nullable();
            $table->unsignedInteger('resolution_cm')->nullable();
            $table->text('licence_note')->nullable();
            // The archive, once built.
            $table->string('path')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedSmallInteger('min_zoom')->nullable();
            $table->unsignedSmallInteger('max_zoom')->nullable();
            $table->decimal('west', 9, 6)->nullable();
            $table->decimal('south', 9, 6)->nullable();
            $table->decimal('east', 9, 6)->nullable();
            $table->decimal('north', 9, 6)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('built_at')->nullable();
            // Replaced by a newer image, never deleted: an area feature records
            // which imagery it was drawn over.
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['coverage_area_id', 'status', 'superseded_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE basemap_layers
              ADD CONSTRAINT basemap_layers_source_known CHECK (source IN ('sentinel2', 'upload')),
              ADD CONSTRAINT basemap_layers_status_known CHECK (status IN ('queued', 'processing', 'ready', 'failed')),
              ADD CONSTRAINT basemap_layers_progress_range CHECK (progress BETWEEN 0 AND 100),
              ADD CONSTRAINT basemap_layers_ready_has_archive CHECK (
                status <> 'ready' OR (path IS NOT NULL AND bytes IS NOT NULL AND checksum IS NOT NULL)
              )
        SQL);

        // One current image per mandate and source. Building a new one
        // supersedes the old in the same transaction that marks it ready.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX basemap_layers_one_current
            ON basemap_layers (coverage_area_id, source)
            WHERE status = 'ready' AND superseded_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('basemap_layers');
        DB::statement('ALTER TABLE coverage_areas DROP CONSTRAINT IF EXISTS coverage_areas_boundary_source_known');

        Schema::table('coverage_areas', function (Blueprint $table): void {
            $table->dropColumn(['boundary_source', 'boundary_file']);
        });
    }
};
