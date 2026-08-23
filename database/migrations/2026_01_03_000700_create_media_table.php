<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Photographs, on a private disk and served only through signed temporary URLs.
 *
 * Three positions are recorded per photograph and they are deliberately kept
 * apart: where the EXIF says the camera was, where the device claimed to be, and
 * where the subject stands. Agreement between them is evidence. Disagreement is
 * the finding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();

            $table->morphs('mediable');

            // facade, signage, interior, document, street_context.
            $table->string('kind', 24);

            $table->string('disk', 32)->default('media');
            $table->string('disk_path');
            $table->string('thumb_path')->nullable();

            // Content hash. Two officers submitting the same file is either a
            // shared photo or a reused one, and both need looking at.
            $table->string('sha256', 64);

            $table->unsignedBigInteger('bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            $table->timestamp('captured_at')->nullable();

            // Whatever the file's own metadata says, kept verbatim.
            $table->jsonb('exif')->nullable();

            // How far the camera was from the structure it claims to show. A
            // facade photographed from four hundred metres away is not a facade.
            $table->decimal('distance_from_subject_m', 10, 2)->nullable();

            // False when the file carries no camera metadata, which is what a
            // screenshot or a downloaded image looks like.
            $table->boolean('from_device_camera')->nullable();

            $table->foreignId('captured_by')->constrained('users');
            $table->foreignId('field_session_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 24)->default('stored');
            $table->uuid('client_uuid')->unique();

            $table->timestamps();

            $table->index(['mediable_type', 'mediable_id', 'kind']);
            $table->index('sha256');
        });

        // Where the EXIF says the photograph was taken.
        DB::statement('ALTER TABLE media ADD COLUMN capture_point geography(Point, 4326)');

        // Where the device said it was at that moment. The two disagreeing is the
        // signal; storing one overwritten by the other would erase it.
        DB::statement('ALTER TABLE media ADD COLUMN device_reported_point geography(Point, 4326)');

        DB::statement('CREATE INDEX media_capture_point_gist ON media USING GIST (capture_point)');
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
