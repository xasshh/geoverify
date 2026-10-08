<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Actions;

use App\Domain\AreaCapture\Models\AreaVerificationTask;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sends officers to ground-truth a sample of what was drawn at the desk.
 *
 * The sample is the campaign's verification rate of the desk-drawn and
 * imported features nobody has checked and nobody is already sent to. It is
 * chosen by a hash of the feature's uuid rather than at random, so running
 * this twice picks the same features: a sample that changed every time it was
 * asked for would be no sample at all.
 *
 * Each goes to the officer working nearest to it: of the officers with open
 * cells in the campaign, the one whose cells are closest, measured in PostGIS.
 * A campaign with no officer in the field yet leaves the task unassigned, to
 * be picked up when somebody is.
 */
final class GenerateVerificationTasks
{
    /** @return array{sampled: int, assigned: int, unassigned: int} */
    public function __invoke(Campaign $campaign, User $actor): array
    {
        $candidates = (int) DB::scalar(<<<'SQL'
            SELECT count(*)
              FROM area_features f
              JOIN area_feature_revisions r ON r.id = f.current_revision_id
             WHERE f.campaign_id = ?
               AND f.status = 'live'
               AND f.verification_status = 'unverified'
               AND r.capture_method IN ('desk_digitised', 'imported')
               AND NOT EXISTS (SELECT 1 FROM area_verification_tasks t WHERE t.area_feature_id = f.id)
        SQL, [$campaign->id]);

        // Everything ever sent counts toward the sample, so the rate is of
        // the whole desk population and a rerun adds only for what is new.
        $alreadySent = (int) DB::scalar(<<<'SQL'
            SELECT count(*) FROM area_verification_tasks t
              JOIN area_features f ON f.id = t.area_feature_id
             WHERE f.campaign_id = ?
        SQL, [$campaign->id]);

        $target = (int) ceil(($candidates + $alreadySent) * ($campaign->verification_sample_pct / 100));
        $wanted = max(0, $target - $alreadySent);

        if ($wanted === 0) {
            return ['sampled' => 0, 'assigned' => 0, 'unassigned' => 0];
        }

        return DB::transaction(function () use ($campaign, $actor, $wanted): array {
            // The sample, and for each feature the nearest officer working
            // the campaign (by their open cells), in one query.
            $rows = DB::select(<<<'SQL'
                WITH sample AS (
                    SELECT f.id, r.geom
                      FROM area_features f
                      JOIN area_feature_revisions r ON r.id = f.current_revision_id
                     WHERE f.campaign_id = ?
                       AND f.status = 'live'
                       AND f.verification_status = 'unverified'
                       AND r.capture_method IN ('desk_digitised', 'imported')
                       AND NOT EXISTS (SELECT 1 FROM area_verification_tasks t WHERE t.area_feature_id = f.id)
                     ORDER BY md5(f.client_uuid::text)
                     LIMIT ?
                ),
                workers AS (
                    SELECT a.user_id, ST_Union(g.boundary) AS ground
                      FROM assignments a
                      JOIN grid_cells g ON g.id = a.grid_cell_id
                      JOIN coverage_areas ca ON ca.id = g.coverage_area_id
                      JOIN users u ON u.id = a.user_id AND u.status = 'active'
                     WHERE a.closed_at IS NULL AND ca.campaign_id = ?
                     GROUP BY a.user_id
                )
                SELECT s.id AS feature_id,
                       (SELECT w.user_id FROM workers w
                         ORDER BY ST_Distance(w.ground::geography, s.geom::geography) LIMIT 1) AS officer_id
                  FROM sample s
            SQL, [$campaign->id, $wanted, $campaign->id]);

            $assigned = 0;

            foreach ($rows as $row) {
                AreaVerificationTask::query()->create([
                    'area_feature_id' => (int) $row->feature_id,
                    'assigned_to' => $row->officer_id === null ? null : (int) $row->officer_id,
                    'assigned_by' => $actor->id,
                    'status' => AreaVerificationTask::STATUS_OPEN,
                ]);

                $assigned += $row->officer_id === null ? 0 : 1;
            }

            VerificationEvent::record($campaign, 'area_features.sent_for_verification', $actor, [
                'sampled' => count($rows),
                'assigned' => $assigned,
                'rate_pct' => $campaign->verification_sample_pct,
            ]);

            return ['sampled' => count($rows), 'assigned' => $assigned, 'unassigned' => count($rows) - $assigned];
        });
    }
}
