<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a person agreed to, in the words they were shown, on the day.
 *
 * `consent_records` says that consent exists. This says what it was, and the
 * difference is the whole point: a record that consent was given, with the
 * disclosure text living in a Blade file that has been edited nine times since,
 * cannot tell anybody what was actually agreed. The receipt copies the wording
 * in rather than pointing at it.
 *
 * Downloadable, because the NDPA gives the person a right to a copy and a right
 * that requires filing a support ticket is not much of a right. The token is
 * what makes that possible without a session: a receipt is handed to somebody
 * who may no longer have an account here.
 *
 * Append only, like everything else that answers a regulator. Withdrawal writes
 * a new row rather than editing the old one, because "they agreed in March and
 * withdrew in September" is two facts and a system that keeps only the second
 * has destroyed evidence it was obliged to keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_receipts', function (Blueprint $table): void {
            $table->id();

            $table->string('token', 64)->unique();

            $table->foreignId('processing_purpose_id')->constrained()->restrictOnDelete();
            $table->foreignId('consent_record_id')->nullable()->constrained()->nullOnDelete();

            // What the consent is about. Polymorphic because an enterprise, a
            // structure and a party all generate them.
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            // Who agreed, on which guard. A party and a staff member are not
            // interchangeable here and the receipt has to say which it was.
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();

            $table->boolean('granted');

            // The wording as shown, frozen. Not a reference to a template.
            $table->text('disclosure');
            $table->string('disclosure_version', 32);
            $table->string('lawful_basis', 48);
            $table->string('language', 16)->default('en');

            // Exactly which fields the agreement covered, so a later widening
            // of the form cannot retroactively claim to have been agreed.
            $table->jsonb('scope')->default('[]');

            $table->timestamp('agreed_at');

            // Written once, by the withdrawal that supersedes this receipt.
            $table->timestamp('withdrawn_at')->nullable();
            $table->unsignedBigInteger('withdrawn_by_receipt_id')->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['processing_purpose_id', 'agreed_at']);

            $table->foreign('withdrawn_by_receipt_id')
                ->references('id')->on('consent_receipts')->nullOnDelete();
        });

        // Append only, enforced where it cannot be argued with. Same shape as
        // the verification_events triggers, for the same reason: a log that the
        // application layer could rewrite is not evidence of anything.
        //
        // One exception, and only one: the withdrawal stamp. A receipt that
        // could never be marked superseded would push withdrawal into some
        // other table, which is exactly how the two halves of a consent history
        // drift apart.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION consent_receipts_are_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' OR TG_OP = 'TRUNCATE' THEN
                    RAISE EXCEPTION 'consent_receipts is append only';
                END IF;

                IF OLD.withdrawn_at IS NOT NULL THEN
                    RAISE EXCEPTION 'this receipt has already been withdrawn';
                END IF;

                IF (to_jsonb(NEW) - 'withdrawn_at' - 'withdrawn_by_receipt_id' - 'updated_at')
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - 'withdrawn_at' - 'withdrawn_by_receipt_id' - 'updated_at') THEN
                    RAISE EXCEPTION 'a consent receipt records what was agreed and cannot be edited';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER consent_receipts_no_delete
                BEFORE DELETE ON consent_receipts
                FOR EACH ROW EXECUTE FUNCTION consent_receipts_are_append_only();

            CREATE TRIGGER consent_receipts_no_truncate
                BEFORE TRUNCATE ON consent_receipts
                FOR EACH STATEMENT EXECUTE FUNCTION consent_receipts_are_append_only();

            CREATE TRIGGER consent_receipts_no_edit
                BEFORE UPDATE ON consent_receipts
                FOR EACH ROW EXECUTE FUNCTION consent_receipts_are_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_receipts');

        DB::unprepared('DROP FUNCTION IF EXISTS consent_receipts_are_append_only() CASCADE');
    }
};
