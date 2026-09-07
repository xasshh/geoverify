<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the payment provider told us, recorded before it is believed.
 *
 * Every webhook is written here first and acted on second. That ordering is the
 * whole point: a provider retries, sometimes for days, and a handler that acts
 * first and records second will take the money twice on the retry.
 *
 * Idempotent on the provider's own event id, which is the only identifier both
 * sides agree on. A replay finds the row already present and does nothing,
 * which is a test in the gate rather than a hope.
 *
 * The signature is verified before anything is trusted, and the result is
 * stored rather than assumed: an event that arrived with a bad signature is
 * evidence about somebody probing us, and deleting it would throw that away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();

            $table->string('provider', 32)->default('paystack');
            $table->string('provider_event_id', 128);
            $table->string('event_type', 64);

            $table->boolean('signature_verified');

            // The provider's reference for the payment, which is what ties an
            // event to an order without trusting anything else in the payload.
            $table->string('payment_reference', 128)->nullable();

            $table->jsonb('payload');

            // Null until it has been acted on. A verified event that is never
            // processed is a queue that has stalled, and that has to be
            // visible rather than inferred from an absence.
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_note')->nullable();

            $table->timestamps();

            // The idempotency key. A replay is refused here rather than in
            // application code, so a race between two workers cannot both win.
            $table->unique(['provider', 'provider_event_id']);
            $table->index('payment_reference');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
