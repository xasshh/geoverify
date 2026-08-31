<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the exercise is worth, and what has been paid.
 *
 * Its own table, and that is the whole point. Columns on `campaigns` would ride
 * along in every model instance a client screen ever touched: one forgotten
 * `$hidden`, one `toArray()`, one Inertia prop spread, and a client is reading
 * their own contract value back off the page. A separate table cannot leak by
 * accident, because reaching it takes a relationship that the client-side
 * queries simply never load.
 *
 * One row per campaign, enforced by a unique key rather than by convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_commercials', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('campaign_id')->unique()->constrained()->cascadeOnDelete();

            // Naira by default, but stamped rather than assumed: a mandate
            // priced in dollars and read back years later must not be silently
            // reinterpreted by whatever the app's default is that day.
            $table->decimal('contract_value', 14, 2)->nullable();
            $table->string('currency', 3)->default('NGN');

            $table->string('payment_status', 24)->default('unpaid');
            $table->timestamp('paid_at')->nullable();

            $table->text('internal_notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_commercials');
    }
};
