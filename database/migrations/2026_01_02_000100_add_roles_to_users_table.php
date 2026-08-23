<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three roles, and no more.
 *
 * officer     captures in the field. Sees only their own assignments.
 * supervisor  assigns work and reviews what comes back.
 * admin       manages mandates, people and devices.
 *
 * A person is one role. Anything finer belongs in a policy, not in a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 24)->default('officer')->after('email');

            // Suspended keeps the person and their history while stopping them
            // working. Nothing in this system is deleted, people included: an
            // officer's captures stay attributable after they leave.
            $table->string('status', 24)->default('active')->after('role');

            // What appears on an evidence pack beside a capture.
            $table->string('staff_ref', 32)->nullable()->after('status');
            $table->string('phone', 32)->nullable()->after('staff_ref');

            $table->timestamp('last_active_at')->nullable();

            $table->index(['role', 'status']);
            $table->unique('staff_ref');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role', 'status']);
            $table->dropUnique(['staff_ref']);
            $table->dropColumn(['role', 'status', 'staff_ref', 'phone', 'last_active_at']);
        });
    }
};
