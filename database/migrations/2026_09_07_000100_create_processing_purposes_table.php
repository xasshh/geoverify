<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Why this system is allowed to hold what it holds.
 *
 * `consent_records` already carries a `lawful_basis` and a `purpose` as loose
 * strings, written by whichever code path happened to be recording. That was
 * survivable while one flow wrote them and is not survivable now: a register
 * that processes for four different reasons under three different bases cannot
 * answer "on what basis do you hold this" from a column whose vocabulary is
 * whatever the last developer typed.
 *
 * So the purposes become rows, and the strings become references to them. The
 * table is small and closed, because adding a purpose is a decision somebody
 * signs off rather than a form somebody fills in. `retention_months` sits here too, so the answer to "how long" travels
 * with the reason rather than being reimplemented per feature.
 *
 * The four purposes are inserted here rather than seeded. Code names them by
 * constant, so a missing row is a fatal error rather than an empty list, and a
 * lookup table the application cannot run without belongs with the schema. It
 * also means changing a disclosure is a migration: "when did the wording
 * change, and to what" is exactly the question this table exists to answer, and
 * a re-runnable seeder would overwrite the answer every deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_purposes', function (Blueprint $table): void {
            $table->id();

            // The key a code path names. Short, stable, and referenced by
            // consent_records.purpose rather than duplicated into it.
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->text('description');

            // NDPA Article 25 grounds, named as the Act names them so the
            // mapping to the law is a lookup rather than an argument.
            $table->string('lawful_basis', 48);

            // What we tell the person, in the words we tell them. Kept here so
            // a receipt can reproduce the wording that was current when they
            // agreed rather than the wording current when they ask.
            $table->text('disclosure');
            $table->string('disclosure_version', 32);

            $table->unsignedSmallInteger('retention_months')->nullable();

            // A purpose is retired, never deleted: receipts reference it and a
            // receipt that cannot explain itself is not a receipt.
            $table->timestamp('retired_at')->nullable();

            $table->timestamps();
        });

        $now = Carbon::now(config('app.timezone'));

        // Literal keys rather than the model's constants. A migration that
        // reaches into application code stops being runnable the day somebody
        // renames that class, and this one has to run on an empty database
        // years from now.
        DB::table('processing_purposes')->insert(array_map(
            static fn (array $purpose): array => $purpose + ['created_at' => $now, 'updated_at' => $now],
            [
                [
                    'key' => 'field_enumeration',
                    'name' => 'Field enumeration',
                    'description' => 'Recording that a business exists at a place, on behalf of the body that commissioned the exercise.',
                    'lawful_basis' => 'public_interest',
                    'disclosure' => 'An officer is recording this business for the national register. We keep the trading name, what the business does, the building it occupies and the date. This does not put the business in any public directory, and nothing here is shared outside the commissioning body without a separate agreement.',
                    'disclosure_version' => '2026-01-a',
                    'retention_months' => null,
                ],
                [
                    'key' => 'ownership_claim',
                    'name' => 'Ownership claim',
                    'description' => 'Establishing that a person controls a business already on the register.',
                    'lawful_basis' => 'contract',
                    'disclosure' => 'To hand you this listing we send a code to the number recorded for the business and keep a record that you proved control of it. We keep your name, your phone number and the claim itself so the decision can be explained later or challenged by somebody else.',
                    'disclosure_version' => '2026-03-a',
                    'retention_months' => null,
                ],
                [
                    'key' => 'paid_verification',
                    'name' => 'Paid verification',
                    'description' => 'Sending an officer to check a business, and issuing a certificate that anybody can check.',
                    'lawful_basis' => 'contract',
                    'disclosure' => 'An officer will visit this business and record what they find, whatever that is. The result becomes a certificate carrying a code that anybody holding it can check against this register: the code shows the business name, the ward and local government, the finding and the date. It does not show your phone number, your exact coordinates or any photograph.',
                    'disclosure_version' => '2026-09-a',
                    'retention_months' => null,
                ],
                [
                    'key' => 'public_directory',
                    'name' => 'Public directory',
                    'description' => 'Showing a business in the directory anybody can search.',
                    'lawful_basis' => 'consent',
                    'disclosure' => 'Publishing puts this business in the public directory, where anybody can find it and search engines can index it. We show the trading name, what the business does, the ward and local government, the opening hours and whatever you add yourself. We never publish the phone number an officer recorded at the door, the exact coordinates or the photographs. You can withdraw at any time and the listing comes down immediately.',
                    'disclosure_version' => '2026-09-a',
                    'retention_months' => null,
                ],
            ],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_purposes');
    }
};
