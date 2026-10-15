<?php

declare(strict_types=1);

namespace App\Domain\Election\Actions;

use App\Domain\Campaign\Enums\CaptureMode;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Election\Models\PollingUnit;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A list of polling units, read from a CSV, into a campaign.
 *
 * The list is somebody else's: INEC's, a client's, a public compilation. So
 * the column names are matched loosely (code, pu_code, delimitation...), every
 * row is read before anything is written, and a row that cannot be used is
 * reported by line rather than guessed at. A code already on the campaign is
 * updated in what the list says (name, place, listed point) and never in what
 * an officer found: observations are not the list's to change.
 *
 * Boundary ids come from the listed point in PostGIS, never from the state,
 * LGA and ward names, which are kept as the list's own text.
 */
final class ImportPollingUnits
{
    public const MAX_ROWS = 60_000;

    /** Our field, and the headings a list might use for it, lower case and squeezed. */
    private const COLUMNS = [
        'code' => ['code', 'inec_code', 'inecode', 'pu_code', 'pucode', 'polling_unit_code', 'pollingunitcode', 'delimitation', 'pu_id', 'id'],
        'name' => ['name', 'pu_name', 'puname', 'polling_unit', 'pollingunit', 'polling_unit_name', 'pollingunitname', 'location'],
        'state' => ['state', 'state_name', 'statename'],
        'lga' => ['lga', 'lga_name', 'lganame', 'local_government', 'localgovernment', 'local_government_area'],
        'ward' => ['ward', 'ward_name', 'wardname', 'registration_area', 'registrationarea', 'ra', 'ra_name'],
        'latitude' => ['latitude', 'lat', 'y'],
        'longitude' => ['longitude', 'lng', 'lon', 'long', 'x'],
        'description' => ['description', 'landmark', 'remark', 'remarks', 'address', 'notes'],
        'voters' => ['voters', 'registered_voters', 'registeredvoters', 'pvc', 'total_registered'],
    ];

    /**
     * @return array{created: int, updated: int, rows: int, errors: list<string>}
     */
    public function __invoke(Campaign $campaign, User $by, string $path): array
    {
        if (! $by->supervises()) {
            throw new RuntimeException('Only a supervisor or an administrator imports a list.');
        }

        if (! $campaign->captures(CaptureMode::PollingUnits)) {
            throw new RuntimeException('This campaign does not check polling units. Turn that on in the campaign first.');
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('The file could not be read.');
        }

        try {
            [$rows, $errors] = $this->read($handle);
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw new RuntimeException($errors === [] ? 'The file has no polling units in it.' : 'No row could be used. '.implode(' ', array_slice($errors, 0, 5)));
        }

        $before = PollingUnit::query()->where('campaign_id', $campaign->id)->count();

        DB::transaction(function () use ($campaign, $by, $rows): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                $values = [];
                $bindings = [];

                foreach ($chunk as $row) {
                    $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, '
                        .($row['longitude'] === null ? 'NULL' : 'ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography')
                        .", 'listed', ?, now(), now())";
                    array_push($bindings, $campaign->id, $row['code'], $row['name'], $row['description'], $row['state'], $row['lga'], $row['ward'], $row['voters']);

                    if ($row['longitude'] !== null) {
                        array_push($bindings, $row['longitude'], $row['latitude']);
                    }

                    $bindings[] = $by->id;
                }

                DB::insert(
                    'INSERT INTO polling_units (campaign_id, inec_code, name, description, listed_state, listed_lga, listed_ward, registered_voters, listed_point, status, imported_by, created_at, updated_at) VALUES '
                    .implode(', ', $values)
                    .' ON CONFLICT (campaign_id, inec_code) DO UPDATE SET
                        name = EXCLUDED.name,
                        description = COALESCE(EXCLUDED.description, polling_units.description),
                        listed_state = COALESCE(EXCLUDED.listed_state, polling_units.listed_state),
                        listed_lga = COALESCE(EXCLUDED.listed_lga, polling_units.listed_lga),
                        listed_ward = COALESCE(EXCLUDED.listed_ward, polling_units.listed_ward),
                        registered_voters = COALESCE(EXCLUDED.registered_voters, polling_units.registered_voters),
                        listed_point = COALESCE(EXCLUDED.listed_point, polling_units.listed_point),
                        updated_at = now()',
                    $bindings,
                );
            }

            // Where the list puts a unit nobody has confirmed yet, in PostGIS.
            DB::update(<<<'SQL'
                UPDATE polling_units u
                   SET state_id = (SELECT b.id FROM admin_boundaries b WHERE b.level = 'state' AND ST_Contains(b.boundary, u.listed_point::geometry) LIMIT 1),
                       lga_id = (SELECT b.id FROM admin_boundaries b WHERE b.level = 'lga' AND ST_Contains(b.boundary, u.listed_point::geometry) LIMIT 1),
                       ward_id = (SELECT b.id FROM admin_boundaries b WHERE b.level = 'ward' AND ST_Contains(b.boundary, u.listed_point::geometry) LIMIT 1)
                 WHERE u.campaign_id = ? AND u.confirmed_point IS NULL AND u.listed_point IS NOT NULL
            SQL, [$campaign->id]);
        });

        $after = PollingUnit::query()->where('campaign_id', $campaign->id)->count();
        $created = $after - $before;

        VerificationEvent::record($campaign, 'polling_units.imported', $by, [
            'rows' => count($rows),
            'created' => $created,
            'updated' => count($rows) - $created,
            'refused' => count($errors),
        ]);

        return ['created' => $created, 'updated' => count($rows) - $created, 'rows' => count($rows), 'errors' => $errors];
    }

    /**
     * Every row, checked, before anything is written.
     *
     * @param  resource  $handle
     * @return array{0: list<array{code: string, name: string, description: string|null, state: string|null, lga: string|null, ward: string|null, voters: int|null, latitude: float|null, longitude: float|null}>, 1: list<string>}
     */
    private function read($handle): array
    {
        $header = fgetcsv($handle, escape: '');
        if ($header === false) {
            return [[], ['The file is empty.']];
        }

        // A spreadsheet saved as CSV often starts with a byte order mark.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $index = $this->map($header);

        if (! isset($index['code'], $index['name'])) {
            throw new RuntimeException('The first row must name the columns, with at least a polling unit code and a name (for example "code" and "name").');
        }

        $rows = [];
        $errors = [];
        $seen = [];
        $line = 1;

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = 'Only the first '.number_format(self::MAX_ROWS).' units were read. Split the list.';
                break;
            }

            $get = static fn (string $field): ?string => isset($index[$field]) && isset($cells[$index[$field]]) && trim((string) $cells[$index[$field]]) !== ''
                ? trim((string) $cells[$index[$field]])
                : null;

            $code = $get('code');
            $name = $get('name');

            if ($code === null || $name === null) {
                $errors[] = "Line {$line}: no code or no name.";

                continue;
            }

            $code = mb_strtoupper(mb_substr($code, 0, 40));

            if (isset($seen[$code])) {
                $errors[] = "Line {$line}: code {$code} is already on line {$seen[$code]}.";

                continue;
            }

            $lat = $get('latitude');
            $lng = $get('longitude');
            $latitude = $lat !== null && is_numeric($lat) ? (float) $lat : null;
            $longitude = $lng !== null && is_numeric($lng) ? (float) $lng : null;

            // Nigeria, loosely. A point outside it is a typo or swapped columns:
            // the unit is kept, without a point, and the line is reported.
            if ($latitude !== null && $longitude !== null && ($latitude < 4.0 || $latitude > 14.0 || $longitude < 2.5 || $longitude > 15.0)) {
                $errors[] = "Line {$line}: {$code} has a point outside Nigeria ({$lat}, {$lng}), kept without it.";
                $latitude = $longitude = null;
            }

            if ($latitude === null || $longitude === null) {
                $latitude = $longitude = null;
            }

            $voters = $get('voters');
            $seen[$code] = $line;
            $rows[] = [
                'code' => $code,
                'name' => mb_substr($name, 0, 300),
                'description' => ($d = $get('description')) === null ? null : mb_substr($d, 0, 1000),
                'state' => ($s = $get('state')) === null ? null : mb_substr($s, 0, 120),
                'lga' => ($l = $get('lga')) === null ? null : mb_substr($l, 0, 120),
                'ward' => ($w = $get('ward')) === null ? null : mb_substr($w, 0, 160),
                'voters' => $voters !== null && ctype_digit(str_replace([',', ' '], '', $voters)) ? (int) str_replace([',', ' '], '', $voters) : null,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }

        return [$rows, $errors];
    }

    /**
     * @param  list<string|null>  $header
     * @return array<string, int>
     */
    private function map(array $header): array
    {
        $index = [];

        foreach ($header as $i => $heading) {
            $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim((string) $heading))), '_');

            foreach (self::COLUMNS as $field => $aliases) {
                if (! isset($index[$field]) && in_array($key, $aliases, true)) {
                    $index[$field] = $i;
                }
            }
        }

        return $index;
    }
}
