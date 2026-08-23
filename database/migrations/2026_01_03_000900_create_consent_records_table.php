<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NDPA 2023 consent, captured where the law requires it: at the point of
 * collection, by the officer standing there, not inferred later from a policy.
 *
 * The script version is recorded because scripts change, and a consent given in
 * March under one wording is not consent to whatever the wording says in
 * December. Retention is expressed here in code rather than only in a policy
 * document, so it can actually be enforced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table): void {
            $table->id();

            $table->morphs('subject');

            // Which script the officer read out, verbatim, identified by version.
            $table->string('script_version', 32);
            $table->string('script_language', 16)->default('en');

            $table->boolean('granted');

            // Who gave it, in the words they gave: "the owner", "the manager on
            // duty". Not a name, because a name is more personal data.
            $table->string('given_by_role', 64)->nullable();

            // The lawful basis this processing runs under, per purpose. Consent is
            // one basis among several, and recording which applies is the point.
            $table->string('lawful_basis', 48);
            $table->string('purpose', 64);

            // When this record must be reviewed or destroyed. A retention schedule
            // that lives only in a policy is not a retention schedule.
            $table->date('retain_until')->nullable();

            $table->foreignId('recorded_by')->constrained('users');
            $table->foreignId('field_session_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('recorded_at');

            $table->uuid('client_uuid')->unique();

            $table->timestamps();

            $table->index('retain_until');
        });

        // Where consent was taken. Consent recorded three kilometres from the
        // business it concerns did not happen at the point of collection.
        DB::statement('ALTER TABLE consent_records ADD COLUMN recorded_point geography(Point, 4326)');
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
    }
};
