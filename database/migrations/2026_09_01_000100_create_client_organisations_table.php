<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The body that commissions an enumeration exercise.
 *
 * Until now a client was a string on coverage_areas: "Demo Client", typed once
 * per mandate and never joined to anything. That was enough while a mandate was
 * the whole of a piece of work, but a client who commissions mining companies
 * this quarter and hair-extension traders next is one relationship with two
 * exercises under it, and a free text column cannot hold that.
 *
 * `coverage_areas.client_name` stays where it is. It is what the field platform
 * and the evidence packs already print, and rewriting it to a foreign key would
 * be a refactor of shipped ground for the benefit of a screen upstairs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_organisations', function (Blueprint $table): void {
            $table->id();

            $table->string('name');

            // Two to six letters, upper case: NRS, NBS, FCTA. It is the first
            // segment of every campaign code this client is issued, so it is
            // read aloud and typed, and it is unique for the same reason.
            $table->string('short_code', 8)->unique();

            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();

            // Suspended, never deleted. A client whose contract lapses still
            // owns the campaigns they paid for and the records those produced.
            $table->string('status', 24)->default('active');

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_organisations');
    }
};
