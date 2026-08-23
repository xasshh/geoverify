<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity references attached to an enterprise or a person.
 *
 * There is no nin column here, and there is no nin column anywhere else either.
 * That is not an oversight to be corrected later: under NDPA 2023 the number is
 * not ours to keep. What is kept is a hash, the last four digits so a person can
 * confirm which credential was checked, and the verifier's receipt. The number
 * itself is discarded the moment it has been used.
 *
 * The schema is built so storing it is not possible. A column that does not exist
 * cannot be filled in by a well meaning change three milestones from now.
 *
 * CAC registration numbers are public record and are stored in full.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_claims', function (Blueprint $table): void {
            $table->id();

            $table->morphs('claimable');

            // cac, tin, nin, bvn, cert_of_incorporation.
            $table->string('kind', 32);

            // For a CAC number this holds the number itself, because it is public.
            // For a NIN or a BVN it holds a keyed hash and nothing reversible.
            $table->string('reference_token', 128);

            // Enough for a person to recognise which credential was used, and no
            // more. Four digits identifies nobody on its own.
            $table->string('reference_last4', 8)->nullable();

            // What the verifier said the name was. Comparing it to the declared
            // trading name is the actual check.
            $table->string('display_name_returned')->nullable();

            // declared, pending, verified, mismatch, failed.
            $table->string('status', 24)->default('declared');

            $table->string('source', 32)->default('field');
            $table->string('verifier', 48)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('response_ref')->nullable();

            // Encrypted at rest via Laravel's encrypted cast. Holds the receipt,
            // never the credential.
            $table->text('raw_payload')->nullable();

            $table->foreignId('captured_by')->nullable()->constrained('users');
            $table->uuid('client_uuid')->unique();

            $table->timestamps();

            $table->index(['claimable_type', 'claimable_id', 'kind']);
            $table->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_claims');
    }
};
