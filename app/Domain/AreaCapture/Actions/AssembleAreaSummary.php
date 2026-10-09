<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Actions;

use App\Domain\Campaign\Models\Campaign;
use Illuminate\Support\Facades\DB;

/**
 * What a campaign has mapped of the land, in the client's terms.
 *
 * Hectares and counts per class of area, kilometres per class of line, counts
 * of points, how much has been checked on the ground, and how far the
 * verification sample has come against its target. Counted over live
 * features only: a withdrawn feature is on record, not on the map.
 *
 * Every figure is a sum over the current revision of each feature, computed
 * in PostGIS when the revision was written, so the summary and the exports
 * can never disagree about a number.
 */
final class AssembleAreaSummary
{
    /** The colour a class is drawn in, so the legend matches the map. */
    public static function colour(mixed $style): string
    {
        $style = is_string($style) ? json_decode($style, true) : $style;

        return is_array($style) ? (string) ($style['fill'] ?? $style['stroke'] ?? '#4BB8B0') : '#4BB8B0';
    }

    /**
     * @return array{classes: list<array<string, mixed>>, totals: array<string, int|float>, verification: array<string, int|float>}
     */
    public function __invoke(Campaign $campaign): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT fc.id, fc.key, fc.label, fc.geometry_type, fc.style,
                   count(f.id) AS features,
                   coalesce(sum(r.area_ha), 0) AS area_ha,
                   coalesce(sum(r.length_m), 0) / 1000 AS length_km,
                   count(f.id) FILTER (WHERE f.verification_status = 'verified') AS verified,
                   count(f.id) FILTER (WHERE r.capture_method IN ('field_walked', 'field_drawn', 'field_verified')) AS from_field
              FROM feature_classes fc
              LEFT JOIN area_features f ON f.feature_class_id = fc.id AND f.status = 'live'
              LEFT JOIN area_feature_revisions r ON r.id = f.current_revision_id
             WHERE fc.campaign_id = ?
             GROUP BY fc.id
             ORDER BY fc.sort_order, fc.id
        SQL, [$campaign->id]);

        $verification = DB::selectOne(<<<'SQL'
            SELECT count(*) FILTER (WHERE r.capture_method IN ('desk_digitised', 'imported')) AS from_desk,
                   count(*) FILTER (WHERE f.verification_status = 'verified') AS verified,
                   count(*) FILTER (WHERE f.verification_status = 'rejected') AS rejected,
                   count(*) FILTER (WHERE f.verification_status = 'needs_revisit') AS revisit,
                   (SELECT count(*) FROM area_verification_tasks t JOIN area_features ff ON ff.id = t.area_feature_id
                     WHERE ff.campaign_id = ? AND t.status = 'done') AS checks_done,
                   (SELECT count(*) FROM area_verification_tasks t JOIN area_features ff ON ff.id = t.area_feature_id
                     WHERE ff.campaign_id = ? AND t.status = 'open') AS checks_open
              FROM area_features f
              JOIN area_feature_revisions r ON r.id = f.current_revision_id
             WHERE f.campaign_id = ? AND f.status = 'live'
        SQL, [$campaign->id, $campaign->id, $campaign->id]);

        $fromDesk = (int) $verification->from_desk;
        $target = (int) ceil($fromDesk * ($campaign->verification_sample_pct / 100));

        $classes = array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'colour' => self::colour($row->style),
            'key' => (string) $row->key,
            'label' => (string) $row->label,
            'geometryType' => (string) $row->geometry_type,
            'features' => (int) $row->features,
            'areaHa' => round((float) $row->area_ha, 2),
            'lengthKm' => round((float) $row->length_km, 2),
            'verified' => (int) $row->verified,
            'fromField' => (int) $row->from_field,
        ], $rows);

        return [
            'classes' => $classes,
            'totals' => [
                'features' => array_sum(array_column($classes, 'features')),
                'areaHa' => round(array_sum(array_column($classes, 'areaHa')), 2),
                'lengthKm' => round(array_sum(array_column($classes, 'lengthKm')), 2),
                'points' => array_sum(array_map(static fn (array $c): int => $c['geometryType'] === 'point' ? $c['features'] : 0, $classes)),
            ],
            'verification' => [
                'fromDesk' => $fromDesk,
                'samplePct' => $campaign->verification_sample_pct,
                'target' => $target,
                'checksDone' => (int) $verification->checks_done,
                'checksOpen' => (int) $verification->checks_open,
                'verified' => (int) $verification->verified,
                'rejected' => (int) $verification->rejected,
                'revisit' => (int) $verification->revisit,
                'progressPct' => $target === 0 ? 0 : (int) min(100, round(((int) $verification->checks_done / $target) * 100)),
            ],
        ];
    }
}
