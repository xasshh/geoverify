<?php

declare(strict_types=1);

namespace App\Domain\Election\Actions;

use App\Domain\Campaign\Models\Campaign;
use App\Domain\Election\Models\PollingUnitCheck;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A campaign's polling units as a spreadsheet (CSV) or a map file (GeoJSON),
 * one row per unit: the list's own words, what we found, and the accepted
 * answers. The point is the confirmed one where an officer stood there, and
 * the listed one, marked as such, where nobody has yet.
 *
 * Officers are never named in an export. The client bought the units.
 */
final class ExportPollingUnits
{
    public const FORMATS = ['csv', 'geojson'];

    public function __invoke(Campaign $campaign, string $format): StreamedResponse
    {
        $slug = str($campaign->code)->lower()->slug();

        return $format === 'geojson'
            ? response()->streamDownload(fn () => $this->geojson($campaign), "{$slug}-polling-units.geojson", ['Content-Type' => 'application/geo+json'])
            : response()->streamDownload(fn () => $this->csv($campaign), "{$slug}-polling-units.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return list<string> */
    public static function headings(): array
    {
        $headings = ['inec_code', 'name', 'listed_state', 'listed_lga', 'listed_ward', 'registered_voters', 'status', 'found',
            'latitude', 'longitude', 'point_source', 'gps_accuracy_m', 'metres_from_listed_point', 'checked_on', 'lga_by_position', 'ward_by_position'];

        foreach (array_keys(PollingUnitCheck::CHECKLIST) as $key) {
            $headings[] = $key;
            $headings[] = "{$key}_detail";
        }

        $headings[] = 'concerns';
        $headings[] = 'notes';

        return $headings;
    }

    private function csv(Campaign $campaign): void
    {
        $out = fopen('php://output', 'w');
        if ($out === false) {
            return;
        }

        // A byte order mark, so Excel reads the names with their accents.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::headings(), escape: '');

        foreach ($this->rows($campaign) as $row) {
            fputcsv($out, array_map(static fn (mixed $v): string => $v === null ? '' : (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v), $this->flat($row)), escape: '');
        }

        fclose($out);
    }

    private function geojson(Campaign $campaign): void
    {
        echo '{"type":"FeatureCollection","features":[';
        $first = true;

        foreach ($this->rows($campaign) as $row) {
            if ($row->lat === null) {
                continue;
            }

            $properties = array_combine(self::headings(), $this->flat($row));
            unset($properties['latitude'], $properties['longitude']);

            echo ($first ? '' : ',').json_encode([
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [round((float) $row->lng, 6), round((float) $row->lat, 6)]],
                'properties' => $properties,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $first = false;
        }

        echo ']}';
    }

    /** @return list<mixed> */
    private function flat(object $row): array
    {
        /** @var array<string, array{answer: string, detail: string|null}> $answers */
        $answers = $row->answers === null ? [] : (json_decode((string) $row->answers, true) ?: []);
        $settled = in_array($row->status, ['confirmed', 'not_found'], true);

        $values = [
            $row->inec_code, $row->name, $row->listed_state, $row->listed_lga, $row->listed_ward, $row->registered_voters,
            $row->status, $settled ? $row->found : null,
            $row->lat === null ? null : round((float) $row->lat, 6),
            $row->lng === null ? null : round((float) $row->lng, 6),
            $row->point_source,
            $row->confirmed_accuracy_m, $row->listed_distance_m,
            $row->settled_at === null ? null : substr((string) $row->settled_at, 0, 10),
            $row->lga_name, $row->ward_name,
        ];

        foreach (array_keys(PollingUnitCheck::CHECKLIST) as $key) {
            $values[] = $answers[$key]['answer'] ?? null;
            $values[] = $answers[$key]['detail'] ?? null;
        }

        $values[] = implode('; ', array_map(SummarisePollingUnits::concernLabel(...), ManagePollingUnitChecks::concernsIn($answers)));
        $values[] = $settled ? $row->notes : null;

        return $values;
    }

    /** @return iterable<object> */
    private function rows(Campaign $campaign): iterable
    {
        return DB::table('polling_units as u')
            ->leftJoin('polling_unit_checks as c', 'c.id', '=', 'u.settled_by_check_id')
            ->leftJoin('admin_boundaries as l', 'l.id', '=', 'u.lga_id')
            ->leftJoin('admin_boundaries as w', 'w.id', '=', 'u.ward_id')
            ->where('u.campaign_id', $campaign->id)
            ->orderBy('u.inec_code')
            ->selectRaw(<<<'SQL'
                u.inec_code, u.name, u.listed_state, u.listed_lga, u.listed_ward, u.registered_voters, u.status,
                u.confirmed_accuracy_m, u.listed_distance_m, u.settled_at, c.found, c.answers, c.notes,
                l.name AS lga_name, w.name AS ward_name,
                ST_Y(COALESCE(u.confirmed_point, u.listed_point)::geometry) AS lat,
                ST_X(COALESCE(u.confirmed_point, u.listed_point)::geometry) AS lng,
                CASE WHEN u.confirmed_point IS NOT NULL THEN 'confirmed' WHEN u.listed_point IS NOT NULL THEN 'listed' END AS point_source
            SQL)
            ->cursor();
    }
}
