<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enumerate E3: Tier 3's daily visits.
 *
 * A daily visit is an `enumerate_visits` row of kind `monitoring`, dated and
 * numbered within the period, carrying a log (open or not, the hours seen,
 * staff, customers, what happened) where a site visit carries its checklist.
 * The same arrival, photographs and review as the site visit, so an officer
 * and a supervisor learn nothing new.
 *
 * The period starts the day after the location is confirmed and runs the
 * number of calendar days bought. Visits happen on trading days (Monday to
 * Saturday, public holidays excepted). A day nobody filed is `missed`, kept as
 * a row so the log can say so, and paid back pro rata when monitoring closes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enumerate_visits', function (Blueprint $table): void {
            $table->unsignedSmallInteger('day_number')->nullable()->after('kind');
            $table->date('visit_date')->nullable()->after('day_number');
            $table->jsonb('log')->nullable()->after('checklist');
        });

        Schema::table('enumerate_requests', function (Blueprint $table): void {
            $table->date('monitoring_starts_on')->nullable()->after('monitoring_days');
            $table->date('monitoring_ends_on')->nullable()->after('monitoring_starts_on');
            $table->foreignId('monitoring_officer_id')->nullable()->after('monitoring_ends_on')->constrained('users');
            // Trading days with no accepted log, counted when monitoring closes.
            $table->unsignedSmallInteger('monitoring_missed_days')->nullable()->after('monitoring_officer_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE enumerate_visits
              DROP CONSTRAINT enumerate_visits_kind_check,
              DROP CONSTRAINT enumerate_visits_status_check,
              DROP CONSTRAINT enumerate_visits_report_whole;

            ALTER TABLE enumerate_visits
              ADD CONSTRAINT enumerate_visits_kind_check CHECK (kind IN ('site', 'monitoring')),
              ADD CONSTRAINT enumerate_visits_status_check CHECK (status IN ('assigned', 'submitted', 'accepted', 'returned', 'missed')),
              ADD CONSTRAINT enumerate_visits_day_whole CHECK (
                (kind = 'site' AND day_number IS NULL AND visit_date IS NULL)
                OR (kind = 'monitoring' AND day_number IS NOT NULL AND visit_date IS NOT NULL)),
              ADD CONSTRAINT enumerate_visits_report_whole CHECK (
                (submitted_at IS NULL AND checklist IS NULL AND log IS NULL AND report_uuid IS NULL)
                OR (submitted_at IS NOT NULL AND report_uuid IS NOT NULL AND (
                     (kind = 'site' AND checklist IS NOT NULL AND log IS NULL)
                  OR (kind = 'monitoring' AND log IS NOT NULL AND checklist IS NULL))));

            DROP INDEX enumerate_visits_one_open;

            -- One site visit in hand per request, as before.
            CREATE UNIQUE INDEX enumerate_visits_one_open_site
              ON enumerate_visits (enumerate_request_id)
              WHERE kind = 'site' AND status IN ('assigned', 'submitted');

            -- One live visit per request per day. A struck-off log (returned)
            -- or a missed day leaves room for nothing but the record.
            CREATE UNIQUE INDEX enumerate_visits_one_per_day
              ON enumerate_visits (enumerate_request_id, visit_date)
              WHERE kind = 'monitoring' AND status IN ('assigned', 'submitted', 'accepted');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS enumerate_visits_one_per_day;
            DROP INDEX IF EXISTS enumerate_visits_one_open_site;

            ALTER TABLE enumerate_visits
              DROP CONSTRAINT enumerate_visits_kind_check,
              DROP CONSTRAINT enumerate_visits_status_check,
              DROP CONSTRAINT enumerate_visits_day_whole,
              DROP CONSTRAINT enumerate_visits_report_whole;

            ALTER TABLE enumerate_visits
              ADD CONSTRAINT enumerate_visits_kind_check CHECK (kind IN ('site')),
              ADD CONSTRAINT enumerate_visits_status_check CHECK (status IN ('assigned', 'submitted', 'accepted', 'returned')),
              ADD CONSTRAINT enumerate_visits_report_whole CHECK (
                (submitted_at IS NULL AND checklist IS NULL AND report_uuid IS NULL)
                OR (submitted_at IS NOT NULL AND checklist IS NOT NULL AND report_uuid IS NOT NULL));

            CREATE UNIQUE INDEX enumerate_visits_one_open
              ON enumerate_visits (enumerate_request_id) WHERE status IN ('assigned', 'submitted');
        SQL);

        Schema::table('enumerate_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('monitoring_officer_id');
            $table->dropColumn(['monitoring_starts_on', 'monitoring_ends_on', 'monitoring_missed_days']);
        });

        Schema::table('enumerate_visits', function (Blueprint $table): void {
            $table->dropColumn(['day_number', 'visit_date', 'log']);
        });
    }
};
