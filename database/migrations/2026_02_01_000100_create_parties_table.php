<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A party: the accountable entity behind everything done on the portal.
 *
 * An individual or a company. It holds listings, orders verification, pays for
 * it, receives certificates and is the respondent in a dispute. One party, one
 * code, all of it attributable.
 *
 * Deliberately separate from `users`, which is the staff table. An officer and
 * a shop owner are not the same kind of thing, and a Role enum that admitted
 * both would put a member of the public one enum case away from the supervisor
 * console. That blast radius is not worth the saved table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table): void {
            $table->id();

            // NBD-XXXX-XXXX-C. Permanent, checksummed, encodes nothing.
            $table->string('code', 20)->unique();

            // What the party is, which decides what identity evidence applies:
            // an individual proves a NIN, a company proves a CAC registration.
            $table->string('kind', 16);

            // What they call themselves, and what a register would call them.
            // A trader is "Mama Ngozi" and has no legal name at all, so only
            // the display name is required.
            $table->string('display_name');
            $table->string('legal_name')->nullable();

            // The phone is the identity channel for most of this audience, so
            // it is the one contact the platform can rely on reaching.
            $table->string('primary_phone', 32);
            $table->string('primary_email')->nullable();
            $table->string('country', 2)->default('NG');

            // Suspended and closed, never deleted. A party's orders and
            // disputes must stay attributable after they leave.
            $table->string('status', 24)->default('active');

            // The highest tier this party itself has reached, as distinct from
            // any listing it controls. A party is identity verified; a business
            // is location verified.
            $table->string('identity_tier', 32)->default('listed');

            $table->timestamps();

            $table->index('primary_phone');
            $table->index(['status', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
