<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Actions;

use App\Domain\AreaCapture\Models\AreaFeature;
use App\Domain\AreaCapture\Models\AreaFeatureRevision;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;

/**
 * Confidence in one area capture, 0 to 100, with every deduction explained.
 *
 * A walked boundary is a field session's position fixes, so the questions are
 * the ones a faked walk fails: a mock location provider; a speed no one walks
 * at; fixes spaced with machine regularity; no GPS noise at all; and a track
 * that does not follow the shape it claims to have drawn. Every measurement is
 * made in PostGIS over geography. ScoreObservation, which scores buildings, is
 * not touched: its weights and signals are the building workflow's.
 *
 * Desk and imported features score nothing here (null): their confidence
 * comes from the officer who later verifies them.
 */
final class ScoreAreaCapture
{
    /** Walking pace, generously: faster than this between fixes is not on foot. */
    private const WALK_MPS = 3.0;

    /** Faster than any vehicle on these roads. */
    private const TELEPORT_MPS = 40.0;

    public function __invoke(AreaFeatureRevision $revision): ?int
    {
        if (! $revision->isFromTheField()) {
            return null;
        }

        $readings = [];
        $score = 100;

        $deduct = static function (string $key, int $points, string $why, array $evidence = []) use (&$score, &$readings): void {
            $score -= $points;
            $readings[] = ['signal' => $key, 'deduction' => $points, 'why' => $why] + $evidence;
        };

        $threshold = (float) (DB::scalar(
            'SELECT COALESCE(c.field_max_accuracy_m, ca.accuracy_threshold_m)
               FROM area_features f
               JOIN campaigns c ON c.id = f.campaign_id
               JOIN coverage_areas ca ON ca.id = f.coverage_area_id
              WHERE f.id = ?',
            [$revision->area_feature_id],
        ) ?? 15);

        if ($revision->gps_accuracy_m !== null && (float) $revision->gps_accuracy_m > $threshold) {
            $deduct('position_accuracy', 15, 'The position was worse than the campaign allows.', [
                'accuracy_m' => (float) $revision->gps_accuracy_m, 'threshold_m' => $threshold,
            ]);
        }

        if ($revision->field_session_id !== null) {
            $track = DB::selectOne(<<<'SQL'
                WITH fixes AS (
                    SELECT point, recorded_at, accuracy_m, is_mock,
                           ST_Distance(point::geography, lag(point) OVER w::geography) AS step_m,
                           EXTRACT(EPOCH FROM recorded_at - lag(recorded_at) OVER w) AS step_s
                      FROM position_fixes
                     WHERE field_session_id = ?
                    WINDOW w AS (ORDER BY recorded_at)
                ),
                shape AS (SELECT geom FROM area_feature_revisions WHERE id = ?)
                SELECT count(*) AS n,
                       bool_or(is_mock) AS mocked,
                       max(step_m / nullif(step_s, 0)) AS fastest_mps,
                       percentile_cont(0.9) WITHIN GROUP (ORDER BY step_m / nullif(step_s, 0)) AS p90_mps,
                       avg(step_m) AS mean_step_m,
                       stddev_pop(step_m) AS sd_step_m,
                       stddev_pop(accuracy_m) AS sd_accuracy_m,
                       avg(ST_Distance(point::geography,
                           (CASE WHEN GeometryType(shape.geom) IN ('POLYGON', 'MULTIPOLYGON')
                                 THEN ST_Boundary(shape.geom) ELSE shape.geom END)::geography)) AS mean_off_track_m
                  FROM fixes CROSS JOIN shape
            SQL, [$revision->field_session_id, $revision->id]);

            $n = (int) ($track->n ?? 0);

            if ($n > 0 && $track->mocked) {
                $deduct('mock_location', 40, 'The phone reported a mock location provider during the walk.');
            }

            if ($n >= 5 && $track->fastest_mps !== null && (float) $track->fastest_mps > self::TELEPORT_MPS) {
                $deduct('implied_speed', 30, 'Two fixes imply a jump no vehicle makes.', ['fastest_kmh' => round((float) $track->fastest_mps * 3.6)]);
            } elseif ($revision->capture_method === AreaFeatureRevision::METHOD_WALKED
                && $n >= 5 && $track->p90_mps !== null && (float) $track->p90_mps > self::WALK_MPS) {
                $deduct('walking_pace', 15, 'Most of the boundary was covered faster than walking pace.', ['p90_kmh' => round((float) $track->p90_mps * 3.6, 1)]);
            }

            // Real walks are uneven: fixes bunch where somebody slowed down.
            if ($n >= 10 && $track->mean_step_m > 0 && $track->sd_step_m !== null
                && ((float) $track->sd_step_m / (float) $track->mean_step_m) < 0.03) {
                $deduct('regular_spacing', 25, 'The fixes are spaced with a regularity a person walking does not produce.');
            }

            // A real receiver's reported accuracy wanders from fix to fix.
            if ($n >= 10 && $track->sd_accuracy_m !== null && (float) $track->sd_accuracy_m < 0.01) {
                $deduct('no_jitter', 15, 'Every fix reports exactly the same accuracy.');
            }

            if ($revision->capture_method === AreaFeatureRevision::METHOD_WALKED && $n >= 3
                && $track->mean_off_track_m !== null && (float) $track->mean_off_track_m > 30) {
                $deduct('off_track', 25, 'The walk does not follow the shape that was drawn.', ['mean_off_track_m' => round((float) $track->mean_off_track_m)]);
            }
        } elseif ($revision->capture_method === AreaFeatureRevision::METHOD_WALKED) {
            $deduct('no_track', 20, 'A walked boundary arrived with no walk behind it.');
        }

        $score = max(0, $score);

        DB::transaction(function () use ($revision, $score, $readings): void {
            $revision->forceFill(['confidence_score' => $score])->save();

            AreaFeature::query()
                ->whereKey($revision->area_feature_id)
                ->where('current_revision_id', $revision->id)
                ->update(['confidence_score' => $score]);

            VerificationEvent::record($revision->feature, 'area_feature.scored', null, [
                'revision_id' => $revision->id,
                'score' => $score,
                'readings' => $readings,
            ], VerificationEvent::ACTOR_SYSTEM);
        });

        return $score;
    }
}
