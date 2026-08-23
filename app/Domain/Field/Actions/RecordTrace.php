<?php

declare(strict_types=1);

namespace App\Domain\Field\Actions;

use App\Domain\Field\Models\Device;
use App\Domain\Field\Models\FieldSession;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opens a working session and takes the fixes that build its trace.
 *
 * The trace is rebuilt from the stored fixes rather than appended to, and it is
 * built in PostGIS. Distance is measured on the geography type, so it is metres
 * over the ellipsoid rather than degrees, which would be meaningless.
 *
 * Fixes are stored exactly as reported. A mock location flag, a satellite count
 * of zero, an accuracy that never varies: each is a signal, and any of them would
 * be lost by cleaning the data on the way in.
 */
final class RecordTrace
{
    /** Anything beyond this between consecutive fixes is not a person walking. */
    private const IMPLAUSIBLE_SPEED_MPS = 45.0;

    public function startSession(
        User $officer,
        string $clientUuid,
        ?int $assignmentId = null,
        ?Device $device = null,
        ?string $appVersion = null,
        string $integrityVerdict = Device::INTEGRITY_UNVERIFIED,
    ): FieldSession {
        if (! $officer->capturesInTheField()) {
            throw new RuntimeException('Only an active field officer can open a session.');
        }

        if ($device instanceof Device && ! $device->isUsable()) {
            throw new RuntimeException('That device has been revoked. Ask your supervisor to enrol another.');
        }

        $session = FieldSession::query()->firstOrCreate(
            ['client_uuid' => $clientUuid],
            [
                'user_id' => $officer->id,
                'assignment_id' => $assignmentId,
                'device_id' => $device?->id,
                'started_at' => now(),
                'app_version' => $appVersion,
                'integrity_verdict' => $integrityVerdict,
            ],
        );

        if ($session->wasRecentlyCreated) {
            VerificationEvent::record($session, 'session.started', $officer, [
                'device_id' => $device?->device_id,
                'integrity_verdict' => $integrityVerdict,
                'app_version' => $appVersion,
            ]);
        }

        return $session;
    }

    /**
     * @param  list<array<string, mixed>>  $fixes
     * @return array{stored: int, duplicates: int}
     */
    public function appendFixes(FieldSession $session, array $fixes): array
    {
        if ($fixes === []) {
            return ['stored' => 0, 'duplicates' => 0];
        }

        $rows = [];
        $points = [];

        foreach ($fixes as $fix) {
            if (! isset($fix['longitude'], $fix['latitude'], $fix['recorded_at'])) {
                continue;
            }

            $recordedAt = Carbon::parse((string) $fix['recorded_at']);

            $rows[] = [
                'field_session_id' => $session->id,
                'recorded_at' => $recordedAt,
                'accuracy_m' => $this->number($fix, 'accuracy_m'),
                'altitude_m' => $this->number($fix, 'altitude_m'),
                'speed_mps' => $this->number($fix, 'speed_mps'),
                'heading' => $this->number($fix, 'heading'),
                'satellite_count' => $this->integer($fix, 'satellite_count'),
                'hdop' => $this->number($fix, 'hdop'),
                'is_mock' => (bool) ($fix['is_mock'] ?? false),
                'provider' => isset($fix['provider']) ? (string) $fix['provider'] : null,
                'source' => 'device',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $points[] = [
                'lon' => (float) $fix['longitude'],
                'lat' => (float) $fix['latitude'],
                'net_lon' => isset($fix['network_longitude']) ? (float) $fix['network_longitude'] : null,
                'net_lat' => isset($fix['network_latitude']) ? (float) $fix['network_latitude'] : null,
                'at' => $recordedAt,
            ];
        }

        if ($rows === []) {
            return ['stored' => 0, 'duplicates' => 0];
        }

        $before = $session->fixes()->count();

        DB::transaction(function () use ($rows, $points, $session): void {
            foreach ($rows as $i => $row) {
                $point = $points[$i];

                // A fix already stored for this session at this instant is the same
                // fix arriving twice, which is what a retried sync looks like.
                DB::statement(<<<'SQL'
                    INSERT INTO position_fixes (
                        field_session_id, recorded_at, accuracy_m, altitude_m, speed_mps, heading,
                        satellite_count, hdop, is_mock, provider, source, point, network_point,
                        created_at, updated_at
                    )
                    SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                           ST_SetSRID(ST_Point(?, ?), 4326),
                           CASE WHEN ?::float IS NULL THEN NULL
                                ELSE ST_SetSRID(ST_Point(?, ?), 4326) END,
                           now(), now()
                     WHERE NOT EXISTS (
                        SELECT 1 FROM position_fixes
                         WHERE field_session_id = ? AND recorded_at = ?
                     )
                SQL, [
                    $row['field_session_id'], $row['recorded_at'], $row['accuracy_m'], $row['altitude_m'],
                    $row['speed_mps'], $row['heading'], $row['satellite_count'], $row['hdop'],
                    $row['is_mock'], $row['provider'], $row['source'],
                    $point['lon'], $point['lat'],
                    $point['net_lon'], $point['net_lon'], $point['net_lat'],
                    $session->id, $row['recorded_at'],
                ]);
            }

            $this->rebuildTrace($session);
        });

        $after = $session->fixes()->count();
        $stored = $after - $before;

        return ['stored' => $stored, 'duplicates' => count($rows) - $stored];
    }

    /**
     * Rebuilds the session trace and its measures from the stored fixes.
     *
     * The M ordinate carries each vertex's epoch second, which is what makes the
     * trace answer "when" as well as "where". Distance is summed over consecutive
     * pairs on the geography type, in metres.
     */
    public function rebuildTrace(FieldSession $session): void
    {
        DB::statement(<<<'SQL'
            WITH ordered AS (
                SELECT point, recorded_at,
                       lag(point)       OVER (ORDER BY recorded_at) AS prev_point,
                       lag(recorded_at) OVER (ORDER BY recorded_at) AS prev_at
                  FROM position_fixes
                 WHERE field_session_id = ?
            ),
            steps AS (
                SELECT ST_Distance(point::geography, prev_point::geography) AS step_m,
                       EXTRACT(EPOCH FROM (recorded_at - prev_at))          AS step_s
                  FROM ordered WHERE prev_point IS NOT NULL
            ),
            line AS (
                SELECT ST_MakeLine(
                           ST_SetSRID(ST_MakePointM(ST_X(point), ST_Y(point),
                               EXTRACT(EPOCH FROM recorded_at)), 4326)
                           ORDER BY recorded_at
                       ) AS geom,
                       count(*) AS n
                  FROM position_fixes WHERE field_session_id = ?
            )
            UPDATE field_sessions s
               SET trace = CASE WHEN line.n >= 2 THEN line.geom ELSE NULL END,
                   fix_count = line.n,
                   distance_m = COALESCE((SELECT round(sum(step_m))::int FROM steps), 0),
                   -- Only steps that could plausibly be a person moving count as
                   -- working time. A four hour gap is not four hours of work.
                   active_seconds = COALESCE((
                       SELECT round(sum(step_s))::int FROM steps
                        WHERE step_s > 0 AND step_s < 600 AND (step_m / step_s) < ?
                   ), 0),
                   updated_at = now()
              FROM line
             WHERE s.id = ?
        SQL, [$session->id, $session->id, self::IMPLAUSIBLE_SPEED_MPS, $session->id]);
    }

    public function endSession(FieldSession $session, User $officer): FieldSession
    {
        if ($session->ended_at !== null) {
            return $session;
        }

        $this->rebuildTrace($session);
        $session->refresh();
        $session->update(['ended_at' => now()]);

        VerificationEvent::record($session, 'session.ended', $officer, [
            'fixes' => $session->fix_count,
            'distance_m' => $session->distance_m,
            'active_seconds' => $session->active_seconds,
        ]);

        return $session->refresh();
    }

    /** @param  array<string, mixed>  $fix */
    private function number(array $fix, string $key): ?float
    {
        return isset($fix[$key]) && is_numeric($fix[$key]) ? (float) $fix[$key] : null;
    }

    /** @param  array<string, mixed>  $fix */
    private function integer(array $fix, string $key): ?int
    {
        return isset($fix[$key]) && is_numeric($fix[$key]) ? (int) $fix[$key] : null;
    }
}
