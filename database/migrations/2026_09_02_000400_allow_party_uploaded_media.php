<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A document a party uploaded, in the same table as an officer's photographs.
 *
 * One media table, not two. A CAC certificate supporting a correction and a
 * facade photographed at the doorstep are both files attached to a record, and
 * splitting them would mean two storage paths, two signed URL contracts and two
 * places to look when somebody asks what evidence a decision rested on.
 *
 * What must not blur is who produced them. `captured_by` names an officer and
 * carries the weight of a field visit; a party's upload carries the weight of
 * an assertion, which is a different thing entirely. Exactly one author,
 * checked, like enterprise_observations and consent_records before it.
 *
 * The positional columns stay where they are and stay null for a party upload.
 * A document has no capture point, and inventing one would put a coordinate
 * next to a certificate as though somebody had stood somewhere to produce it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->foreignId('uploaded_by_party_id')->nullable()->constrained('parties');

            // The person who acted, kept apart from the party they acted for,
            // the same way a claim and a correction record it.
            $table->foreignId('uploaded_by_account_id')->nullable()->constrained('portal_accounts');
        });

        DB::statement('ALTER TABLE media ALTER COLUMN captured_by DROP NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE media ADD CONSTRAINT media_one_author CHECK (
                (captured_by IS NOT NULL AND uploaded_by_party_id IS NULL)
             OR (captured_by IS NULL AND uploaded_by_party_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE media DROP CONSTRAINT IF EXISTS media_one_author');

        Schema::table('media', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('uploaded_by_account_id');
            $table->dropConstrainedForeignId('uploaded_by_party_id');
        });
    }
};
