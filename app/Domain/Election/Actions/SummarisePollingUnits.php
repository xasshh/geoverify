<?php

declare(strict_types=1);

namespace App\Domain\Election\Actions;

use App\Domain\Campaign\Models\Campaign;
use App\Domain\Election\Models\PollingUnit;
use App\Domain\Election\Models\PollingUnitCheck;
use Illuminate\Support\Facades\DB;

/**
 * What a campaign's polling unit checks add up to, for the console, the
 * client and the report alike, so the three can never disagree.
 *
 * Findings are counted over settled units only (a check a supervisor
 * accepted), because a report nobody has read yet is not yet a finding. No
 * officer is named here: the client bought the polling units, not the people.
 */
final class SummarisePollingUnits
{
    /**
     * @return array{
     *     total: int,
     *     byStatus: array<string, int>,
     *     settled: int,
     *     confirmed: int,
     *     notFound: int,
     *     moved: int,
     *     withoutListedPoint: int,
     *     concerns: list<array{key: string, label: string, count: int}>,
     *     venues: list<array{key: string, label: string, count: int}>,
     *     byLga: list<array{lga: string, total: int, settled: int, confirmed: int, notFound: int, moved: int, withConcerns: int}>
     * }
     */
    public function __invoke(Campaign $campaign): array
    {
        $statuses = DB::table('polling_units')->where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();

        $byStatus = [];
        foreach (PollingUnit::STATUSES as $status) {
            $byStatus[$status] = $statuses[$status] ?? 0;
        }

        $moved = (int) DB::table('polling_units as u')
            ->join('polling_unit_checks as c', 'c.id', '=', 'u.settled_by_check_id')
            ->where('u.campaign_id', $campaign->id)
            ->where('u.status', PollingUnit::CONFIRMED)
            ->where(fn ($q) => $q->where('c.found', 'elsewhere')->orWhere('u.listed_distance_m', '>', PollingUnitCheck::AT_LISTED_M))
            ->count();

        // The accepted answers of every confirmed unit, one row per unit.
        $answers = DB::table('polling_units as u')
            ->join('polling_unit_checks as c', 'c.id', '=', 'u.settled_by_check_id')
            ->where('u.campaign_id', $campaign->id)
            ->where('u.status', PollingUnit::CONFIRMED)
            ->select('u.listed_lga', 'c.answers')
            ->get();

        $concernCounts = array_fill_keys(array_keys(PollingUnitCheck::CONCERNS), 0);
        $venueCounts = array_fill_keys(array_keys(PollingUnitCheck::CHECKLIST['venue'][1]), 0);
        $lgaConcerns = [];

        foreach ($answers as $row) {
            /** @var array<string, array{answer: string, detail: string|null}> $a */
            $a = json_decode((string) $row->answers, true) ?: [];
            $found = ManagePollingUnitChecks::concernsIn($a);

            foreach ($found as $key) {
                $concernCounts[$key]++;
            }

            $venue = $a['venue']['answer'] ?? null;
            if (is_string($venue) && isset($venueCounts[$venue])) {
                $venueCounts[$venue]++;
            }

            if ($found !== []) {
                $lga = (string) ($row->listed_lga ?? 'Not given');
                $lgaConcerns[$lga] = ($lgaConcerns[$lga] ?? 0) + 1;
            }
        }

        $byLga = DB::select(<<<'SQL'
            SELECT COALESCE(u.listed_lga, 'Not given') AS lga,
                   count(*) AS total,
                   count(*) FILTER (WHERE u.status IN ('confirmed', 'not_found')) AS settled,
                   count(*) FILTER (WHERE u.status = 'confirmed') AS confirmed,
                   count(*) FILTER (WHERE u.status = 'not_found') AS not_found,
                   count(*) FILTER (WHERE u.status = 'confirmed' AND (c.found = 'elsewhere' OR u.listed_distance_m > ?)) AS moved
              FROM polling_units u
              LEFT JOIN polling_unit_checks c ON c.id = u.settled_by_check_id
             WHERE u.campaign_id = ?
             GROUP BY 1
             ORDER BY 1
        SQL, [PollingUnitCheck::AT_LISTED_M, $campaign->id]);

        return [
            'total' => array_sum($byStatus),
            'byStatus' => $byStatus,
            'settled' => $byStatus[PollingUnit::CONFIRMED] + $byStatus[PollingUnit::NOT_FOUND],
            'confirmed' => $byStatus[PollingUnit::CONFIRMED],
            'notFound' => $byStatus[PollingUnit::NOT_FOUND],
            'moved' => $moved,
            'withoutListedPoint' => DB::table('polling_units')->where('campaign_id', $campaign->id)->whereNull('listed_point')->count(),
            'concerns' => array_map(static fn (string $key): array => [
                'key' => $key,
                'label' => self::concernLabel($key),
                'count' => $concernCounts[$key],
            ], array_keys($concernCounts)),
            'venues' => array_values(array_filter(array_map(static fn (string $key): array => [
                'key' => $key,
                'label' => PollingUnitCheck::CHECKLIST['venue'][1][$key],
                'count' => $venueCounts[$key],
            ], array_keys($venueCounts)), static fn (array $v): bool => $v['count'] > 0)),
            'byLga' => array_map(static fn (object $r): array => [
                'lga' => (string) $r->lga,
                'total' => (int) $r->total,
                'settled' => (int) $r->settled,
                'confirmed' => (int) $r->confirmed,
                'notFound' => (int) $r->not_found,
                'moved' => (int) $r->moved,
                'withConcerns' => $lgaConcerns[(string) $r->lga] ?? 0,
            ], $byLga),
        ];
    }

    /** A finding, said the way a report says it. */
    public static function concernLabel(string $key): string
    {
        return match ($key) {
            'step_free' => 'Not fully accessible to wheelchair users',
            'shelter' => 'No shade or roof for voters',
            'queue_space' => 'Not enough room to queue',
            'toilet_water' => 'No toilet or drinking water nearby',
            'lighting' => 'No lighting for a late count',
            'signal' => 'No phone signal',
            'vehicle_access' => 'Reachable only on foot',
            'flood_risk' => 'Floods, or looks likely to',
            'security' => 'A security concern was noted',
            default => $key,
        };
    }
}
