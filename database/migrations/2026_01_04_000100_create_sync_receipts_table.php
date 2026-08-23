<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the server has already accepted, so it never accepts it twice.
 *
 * A device on a bad connection retries. It retries because it did not hear the
 * answer, not because the work did not land, and the two are indistinguishable
 * from the handset. Every mutation therefore carries a client generated uuid and
 * a hash of its payload, and this table is the record of which ones have been
 * seen.
 *
 * The uuid alone is not enough. An officer can legitimately edit a record and
 * resend it under the same uuid, which is a different payload and a real change.
 * The uuid says which record; the hash says which version of it. Together they
 * say whether this exact submission has already been applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_receipts', function (Blueprint $table): void {
            $table->id();

            $table->uuid('client_uuid');

            // Hash of the mutation payload. Same uuid with a different hash is a
            // genuine revision; same uuid and same hash is a retry.
            $table->string('payload_hash', 64);

            $table->foreignId('user_id')->constrained();
            $table->string('device_id', 128)->nullable();

            // What the mutation was asking for, kept so a replay can be answered
            // with the same response without re-running the work.
            $table->string('entity', 48);
            $table->string('operation', 24);

            // What it produced. A retry is answered from here.
            $table->string('resulting_type')->nullable();
            $table->unsignedBigInteger('resulting_id')->nullable();

            $table->string('status', 24)->default('applied');
            $table->text('error')->nullable();

            $table->timestamp('received_at');
            $table->timestamps();

            // The idempotency guarantee itself, enforced by the database rather
            // than by the code remembering to check.
            $table->unique(['client_uuid', 'payload_hash']);

            $table->index(['user_id', 'received_at']);
            $table->index('client_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_receipts');
    }
};
