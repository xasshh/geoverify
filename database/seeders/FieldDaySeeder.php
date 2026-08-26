<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Actions\AssignCells;
use App\Domain\Field\Models\Assignment;
use App\Domain\Field\Models\FieldSession;
use App\Domain\Identity\Actions\HashIdentityReference;
use App\Domain\Identity\Models\IdentityClaim;
use App\Domain\Registry\Actions\CaptureEnterprise;
use App\Domain\Registry\Actions\CaptureStructure;
use App\Domain\Registry\Data\StructureCapture;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Actions\ReviewObservation;
use App\Domain\Verification\Actions\ScoreObservation;
use App\Domain\Verification\Enums\ReviewDecision;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A day in the field, so the console has something true to show.
 *
 * Four officers with four different days: one working now, one who has gone
 * quiet, one who has just started, and one whose day was not walked at all. The
 * last of those is the point. A console that only ever shows clean data cannot
 * be judged, because the screens that matter are the ones that fire when
 * something is wrong, and those stay invisible until something is.
 *
 * Every position here is written through PostGIS and every trace is built by it.
 * The walks themselves are generated, which is fixture data rather than spatial
 * computation: nothing is measured in PHP.
 *
 * Additive. Running it twice gives every officer a second session rather than
 * replacing the first, because nothing in this system is hard deleted and a
 * seeder is a poor place to make the first exception.
 */
final class FieldDaySeeder extends Seeder
{
    /**
     * The four days, and what each is meant to prove on screen.
     *
     * @var list<array{email: string, minutes_ago: int, captures: int, fabricated: bool, legs: int}>
     */
    private const DAYS = [
        // Out now, working properly: a dense trace and an uneven pace.
        ['email' => 'bello@geoverify.test', 'minutes_ago' => 6, 'captures' => 7, 'fabricated' => false, 'legs' => 9],
        // Went quiet two hours ago. This is the one a supervisor rings.
        ['email' => 'okafor@geoverify.test', 'minutes_ago' => 132, 'captures' => 5, 'fabricated' => false, 'legs' => 7],
        // Never left the house. Straight line, constant step, constant accuracy,
        // a network position kilometres away, and a capture every three minutes.
        ['email' => 'suleiman@geoverify.test', 'minutes_ago' => 25, 'captures' => 6, 'fabricated' => true, 'legs' => 0],
        // Just started. A short trace and one capture.
        ['email' => 'adeyemi@geoverify.test', 'minutes_ago' => 4, 'captures' => 1, 'fabricated' => false, 'legs' => 3],
    ];

    /** @var list<array{string, string, string}> Trading name, sector, scale. */
    private const BUSINESSES = [
        ['Mama Ngozi Provisions', '4711', 'micro'],
        ['Chidi Motors Spare Parts', '4530', 'micro'],
        ['Blessing Hair Studio', '9602', 'micro'],
        ['Garki Cold Room', '4630', 'small'],
        ['Sunrise Pharmacy', '4772', 'small'],
        ['Alhaji Tailoring Works', '1410', 'micro'],
        ['Zenith Business Centre', '8219', 'micro'],
    ];

    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command?->error('FieldDaySeeder is for local development only.');

            return;
        }

        $area = CoverageArea::query()->orderBy('id')->first();
        $supervisor = User::query()->where('email', 'supervisor@geoverify.test')->first();

        if (! $area instanceof CoverageArea || ! $supervisor instanceof User) {
            $this->command?->error('Needs a coverage area and the field team. Run FieldTeamSeeder and load a mandate first.');

            return;
        }

        $scored = [];

        foreach (self::DAYS as $index => $day) {
            $officer = User::query()->where('email', $day['email'])->first();

            if (! $officer instanceof User) {
                continue;
            }

            $cell = $this->groundFor($officer, $supervisor, $area);

            if (! $cell instanceof GridCell) {
                $this->command?->warn("No cell with buildings left for {$officer->name}.");

                continue;
            }

            $observations = $this->workTheDay($officer, $cell, $day, $index);
            $scored = [...$scored, ...$observations];
        }

        $this->decide($scored, $supervisor);

        $this->command?->info(sprintf(
            'Seeded %d captures across %d officers. Open /console/live.',
            count($scored),
            count(self::DAYS),
        ));
    }

    /** The officer's open assignment, or the busiest free cell with buildings on it. */
    private function groundFor(User $officer, User $supervisor, CoverageArea $area): ?GridCell
    {
        $held = Assignment::query()
            ->where('user_id', $officer->id)
            ->whereNull('closed_at')
            ->first();

        if ($held instanceof Assignment) {
            return GridCell::query()->find($held->grid_cell_id);
        }

        $free = GridCell::query()
            ->where('coverage_area_id', $area->id)
            ->where('footprint_count', '>', 0)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('assignments')
                ->whereColumn('assignments.grid_cell_id', 'grid_cells.id')
                ->whereNull('assignments.closed_at'))
            ->orderByDesc('footprint_count')
            ->first();

        if (! $free instanceof GridCell) {
            return null;
        }

        app(AssignCells::class)->assign([$free->id], $officer, $supervisor);

        return $free;
    }

    /**
     * One session: the walk, the fixes, the trace, and the captures along it.
     *
     * @param  array{email: string, minutes_ago: int, captures: int, fabricated: bool, legs: int}  $day
     * @return list<StructureObservation>
     */
    private function workTheDay(User $officer, GridCell $cell, array $day, int $seed): array
    {
        [$lon, $lat] = $this->centreOf($cell);
        $lastFixAt = now()->subMinutes($day['minutes_ago']);

        $fixes = $day['fabricated']
            ? $this->fabricatedWalk($lon, $lat, $lastFixAt)
            : $this->walk($lon, $lat, $lastFixAt, $day['legs'], $seed);

        $session = FieldSession::query()->create([
            'user_id' => $officer->id,
            'assignment_id' => Assignment::query()
                ->where('grid_cell_id', $cell->id)
                ->whereNull('closed_at')
                ->value('id'),
            'started_at' => $fixes[0]['at'],
            'app_version' => '1.0.0-dev',
            'integrity_verdict' => $day['fabricated'] ? 'unverified' : 'passed',
            'client_uuid' => (string) Str::uuid7(),
        ]);

        $this->recordFixes($session, $fixes);

        return $this->capture($officer, $cell, $session, $fixes, $day, $seed);
    }

    /** @return array{float, float} */
    private function centreOf(GridCell $cell): array
    {
        $row = DB::selectOne(
            'select st_x(centroid::geometry) as lon, st_y(centroid::geometry) as lat from grid_cells where id = ?',
            [$cell->id],
        );

        return [(float) ($row->lon ?? 0.0), (float) ($row->lat ?? 0.0)];
    }

    /**
     * A day of systematic enumeration, walked.
     *
     * Officers do not wander. They work a cell the way anyone covers ground on
     * foot: down one street, across to the next, back along it, which draws a
     * serpentine rather than a circuit. Three things stop that reading as a
     * drawing:
     *
     *  - The grid is rotated per officer. Streets are not aligned to anybody's
     *    screen, and an axis aligned trace is exactly the shape the naturalness
     *    signal exists to catch.
     *  - Legs vary, and a junction is sometimes carried straight through.
     *  - Every fix carries receiver noise, and every doorway carries a dwell,
     *    because standing still for four minutes recording a shop is most of
     *    what the day actually is.
     *
     * @return list<array{lon: float, lat: float, at: Carbon, accuracy: float, satellites: int, mock: bool, network: array{float, float}}>
     */
    private function walk(float $lon, float $lat, Carbon $lastFixAt, int $legs, int $seed): array
    {
        mt_srand($seed * 7919);

        $bearing = (mt_rand(0, 890) / 10.0) * M_PI / 180.0;
        $block = 0.00011;

        // An officer works the cell they were given. A resolution 9 cell is
        // about ten hectares, so a day inside one is a few hundred metres of
        // ground walked over and over, not a route across the district. Without
        // this the trace leaves the hexagon it belongs to and every capture
        // along it trips the containment signal for no reason.
        $reach = 0.00105;

        /** @var list<array{float, float}> $axes */
        $axes = [];

        for ($i = 0; $i < 4; $i++) {
            $angle = $bearing + ($i * M_PI / 2);
            $axes[] = [cos($angle) * $block, sin($angle) * $block];
        }

        $steps = [];
        $x = $lon;
        $y = $lat;

        // Along a street, then across to the next one, then back along it.
        $along = 0;
        $across = 1;
        $direction = 1;

        for ($leg = 0; $leg < max(2, $legs); $leg++) {
            $heading = $direction === 1 ? $along : ($along + 2) % 4;
            $length = 4 + mt_rand(0, 7);

            for ($step = 0; $step < $length; $step++) {
                [$ax, $ay] = $axes[$heading];
                $pace = 0.75 + (mt_rand(0, 70) / 100);

                // The edge of the cell. Turn back rather than walk out of it.
                if (abs(($x + $ax * $pace) - $lon) > $reach
                    || abs(($y + $ay * $pace) - $lat) > $reach) {
                    $heading = ($heading + 2) % 4;
                    [$ax, $ay] = $axes[$heading];
                    $direction = -$direction;
                }

                $x += $ax * $pace;
                $y += $ay * $pace;

                $steps[] = [
                    $x + (mt_rand(-16, 16) / 1_000_000),
                    $y + (mt_rand(-16, 16) / 1_000_000),
                ];

                // A doorway. The officer stops, and the receiver keeps talking.
                if (mt_rand(0, 100) < 22) {
                    foreach (range(1, 2 + mt_rand(0, 3)) as $ignored) {
                        $steps[] = [
                            $x + (mt_rand(-7, 7) / 1_000_000),
                            $y + (mt_rand(-7, 7) / 1_000_000),
                        ];
                    }
                }
            }

            // Across to the next street, and turn back the other way. The
            // across axis flips at the edge too, so the walk folds into the
            // cell instead of marching out of one side of it.
            [$cx, $cy] = $axes[$across];
            $shift = 1.3 + (mt_rand(0, 50) / 100);

            if (abs(($x + $cx * $shift) - $lon) > $reach
                || abs(($y + $cy * $shift) - $lat) > $reach) {
                $across = ($across + 2) % 4;
                [$cx, $cy] = $axes[$across];
            }

            $x += $cx * $shift;
            $y += $cy * $shift;
            $steps[] = [$x, $y];

            $direction = -$direction;

            // Not every block is a right angle. Occasionally the street bends
            // and the whole grid bends with it.
            if (mt_rand(0, 100) < 18) {
                $bearing += (mt_rand(-12, 12) / 100.0);

                for ($i = 0; $i < 4; $i++) {
                    $angle = $bearing + ($i * M_PI / 2);
                    $axes[$i] = [cos($angle) * $block, sin($angle) * $block];
                }
            }
        }

        $fixes = [];
        $at = $lastFixAt->copy()->subSeconds(count($steps) * 20);

        foreach ($steps as [$stepLon, $stepLat]) {
            $at = $at->copy()->addSeconds(8 + mt_rand(0, 34));

            $fixes[] = [
                'lon' => $stepLon,
                'lat' => $stepLat,
                'at' => $at,
                'accuracy' => mt_rand(240, 690) / 100,
                'satellites' => mt_rand(7, 12),
                'mock' => false,
                // A tower derived position, honestly coarse.
                'network' => [$stepLon + mt_rand(-9, 9) / 10_000, $stepLat + mt_rand(-9, 9) / 10_000],
            ];
        }

        return $fixes;
    }

    /**
     * A day that was not walked.
     *
     * Interpolated between two points at a constant step, an accuracy that never
     * moves because it was written down once, a network position that stayed at
     * home while the GNSS went to work, and the mock provider that made all of
     * it possible still running. That last one is free to install and takes
     * thirty seconds, which is why it is the heaviest signal there is.
     *
     * @return list<array{lon: float, lat: float, at: Carbon, accuracy: float, satellites: int, mock: bool, network: array{float, float}}>
     */
    private function fabricatedWalk(float $lon, float $lat, Carbon $lastFixAt): array
    {
        $fixes = [];
        $at = $lastFixAt->copy()->subMinutes(40);

        for ($i = 0; $i < 40; $i++) {
            $at = $at->copy()->addSeconds(60);

            $fixes[] = [
                'lon' => $lon + ($i * 0.00007),
                'lat' => $lat + ($i * 0.00007),
                'at' => $at,
                'accuracy' => 4.0,
                'satellites' => 9,
                'mock' => true,
                'network' => [$lon + 0.061, $lat + 0.058],
            ];
        }

        return $fixes;
    }

    /**
     * @param  list<array{lon: float, lat: float, at: Carbon, accuracy: float, satellites: int, mock: bool, network: array{float, float}}>  $fixes
     */
    private function recordFixes(FieldSession $session, array $fixes): void
    {
        foreach ($fixes as $fix) {
            // point is NOT NULL and deliberately so: a fix without a position is
            // not a fix, so the geometry goes in with the insert.
            DB::insert(<<<'SQL'
                insert into position_fixes (
                    field_session_id, recorded_at, accuracy_m, satellite_count, is_mock,
                    provider, source, created_at, updated_at, point, network_point
                ) values (
                    ?, ?, ?, ?, ?, 'gps', 'device', now(), now(),
                    st_setsrid(st_point(?, ?), 4326),
                    st_setsrid(st_point(?, ?), 4326)
                )
            SQL, [
                $session->id, $fix['at'], $fix['accuracy'], $fix['satellites'], $fix['mock'],
                $fix['lon'], $fix['lat'], $fix['network'][0], $fix['network'][1],
            ]);
        }

        // The trace is a LineStringM whose M carries the epoch second of each
        // vertex, built from the stored fixes exactly as RecordTrace builds it.
        DB::statement(<<<'SQL'
            update field_sessions
               set trace = (
                       select st_makeline(
                           st_setsrid(
                               st_makepointm(st_x(point), st_y(point), extract(epoch from recorded_at)),
                               4326
                           ) order by recorded_at
                       )
                         from position_fixes where field_session_id = ?
                   ),
                   fix_count = (select count(*) from position_fixes where field_session_id = ?),
                   distance_m = coalesce((
                       select st_length(st_makeline(point order by recorded_at)::geography)
                         from position_fixes where field_session_id = ?
                   ), 0)
             where id = ?
        SQL, [$session->id, $session->id, $session->id, $session->id]);
    }

    /**
     * Structures and businesses along the walk, at the pace the day implies.
     *
     * @param  list<array{lon: float, lat: float, at: Carbon, accuracy: float, satellites: int, mock: bool, network: array{float, float}}>  $fixes
     * @param  array{email: string, minutes_ago: int, captures: int, fabricated: bool, legs: int}  $day
     * @return list<StructureObservation>
     */
    private function capture(User $officer, GridCell $cell, FieldSession $session, array $fixes, array $day, int $seed): array
    {
        $structures = app(CaptureStructure::class);
        $enterprises = app(CaptureEnterprise::class);
        $assignmentId = $session->assignment_id;
        $observations = [];

        $types = ['shophouse', 'commercial_block', 'standalone', 'kiosk', 'container'];
        $stride = max(1, intdiv(count($fixes), max(1, $day['captures'])));

        for ($n = 0; $n < $day['captures']; $n++) {
            $fix = $fixes[min(count($fixes) - 1, ($n + 1) * $stride - 1)];

            // A fabricated day is captured on a metronome. A real one is not:
            // one shop is shut, the next owner wants to talk.
            $observedAt = $day['fabricated']
                ? $fixes[0]['at']->copy()->addSeconds(180 * ($n + 1))
                : $fix['at']->copy()->addSeconds(mt_rand(20, 260));

            $structure = $structures->capture(new StructureCapture(
                clientUuid: (string) Str::uuid7(),
                observationUuid: (string) Str::uuid7(),
                gridCellId: $cell->id,
                longitude: $fix['lon'],
                latitude: $fix['lat'],
                structureType: $types[($seed + $n) % count($types)],
                occupancyStatus: 'occupied',
                observedAt: $observedAt,
                accuracyM: $fix['accuracy'],
                floors: 1 + (($seed + $n) % 3),
                unitCount: 1 + (($seed + $n) % 6),
                fieldSessionId: $session->id,
                assignmentId: $assignmentId,
            ), $officer);

            $observation = StructureObservation::query()
                ->where('structure_id', $structure->id)
                ->latest('observed_at')
                ->first();

            if (! $observation instanceof StructureObservation) {
                continue;
            }

            $observations[] = $observation;

            [$name, $sector, $scale] = self::BUSINESSES[($seed * 3 + $n) % count(self::BUSINESSES)];

            $enterprise = $enterprises->capture([
                'client_uuid' => (string) Str::uuid7(),
                'observation_uuid' => (string) Str::uuid7(),
                'structure_id' => $structure->id,
                'trading_name' => $name,
                'sector_code' => $sector,
                'scale_band' => $scale,
                'employee_band' => '1-4',
                'operating_status' => 'operating',
                'years_at_location' => 1 + (($seed + $n) % 12),
                'signage_observed' => ! $day['fabricated'],
                'observed_at' => $observedAt->toIso8601String(),
                'field_session_id' => $session->id,
            ], $officer);

            $this->photograph($structure, $enterprise, $officer, $session, $fix, $day['fabricated']);
            $this->identify($enterprise, $officer, $seed + $n, $day['fabricated']);
        }

        return $observations;
    }

    /**
     * Photographs, with real files behind them.
     *
     * The files are generated and they are labelled as generated. That is a
     * deliberate line: a seeder that produced convincing photographs of
     * Nigerian shopfronts would be manufacturing exactly the kind of evidence
     * this system exists to detect, and it would sit in a demo pack alongside
     * real records with nothing to tell them apart. A frame that says what it
     * is populates the contact sheet and the review screen honestly.
     *
     * Everything around the pixels is real: the SHA-256 is of the actual bytes,
     * the dimensions are read back from the file, and the three positions are
     * kept apart the way the schema intends.
     *
     * @param  array{lon: float, lat: float, at: Carbon, accuracy: float, satellites: int, mock: bool, network: array{float, float}}  $fix
     */
    private function photograph(
        Structure $structure,
        Enterprise $enterprise,
        User $officer,
        FieldSession $session,
        array $fix,
        bool $fabricated,
    ): void {
        $shots = [
            [$structure->getMorphClass(), $structure->id, 'facade', $structure->structure_type],
            [$enterprise->getMorphClass(), $enterprise->id, 'signage', $enterprise->trading_name],
        ];

        foreach ($shots as [$type, $id, $kind, $subject]) {
            $path = 'demo/'.Str::lower(Str::random(24)).'.jpg';
            $bytes = $this->frame($kind, (string) $subject, $fix, $officer);

            Storage::disk('media')->put($path, $bytes);

            $size = getimagesizefromstring($bytes);

            DB::insert(<<<'SQL'
                insert into media (
                    mediable_type, mediable_id, kind, disk, disk_path, sha256, bytes,
                    width, height, captured_at, from_device_camera, distance_from_subject_m,
                    captured_by, field_session_id, status, client_uuid, created_at, updated_at,
                    capture_point, device_reported_point
                ) values (
                    ?, ?, ?, 'media', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'stored', ?, now(), now(),
                    st_setsrid(st_point(?, ?), 4326)::geography,
                    st_setsrid(st_point(?, ?), 4326)::geography
                )
            SQL, [
                $type, $id, $kind, $path,
                hash('sha256', $bytes),
                strlen($bytes),
                $size === false ? null : $size[0],
                $size === false ? null : $size[1],
                $fix['at'],
                // A fabricated day has no camera behind its photographs.
                ! $fabricated,
                $fabricated ? null : mt_rand(3, 22),
                $officer->id,
                $session->id,
                (string) Str::uuid7(),
                $fix['lon'], $fix['lat'],
                $fix['lon'], $fix['lat'],
            ]);
        }
    }

    /**
     * What was checked about who runs the business.
     *
     * Two kinds on purpose, because they are treated differently everywhere
     * downstream. A CAC registration is public record and is stored and
     * exported in full. A NIN goes through the same hashing the field client
     * uses: what survives is a keyed token, the last four digits so a person
     * can confirm which credential was checked, and the fact of the check. The
     * number itself is never held, here or anywhere.
     */
    private function identify(Enterprise $enterprise, User $officer, int $n, bool $fabricated): void
    {
        $hasher = app(HashIdentityReference::class);

        // Not every business in an informal market is registered, and a seeder
        // that gave all of them a CAC number would be describing a different
        // economy from the one this system was built to count.
        if ($n % 3 !== 0) {
            $cac = $hasher->hash(IdentityClaim::KIND_CAC, 'RC'.(1_000_000 + ($n * 7_919)));

            IdentityClaim::query()->create([
                'claimable_type' => $enterprise->getMorphClass(),
                'claimable_id' => $enterprise->id,
                'kind' => IdentityClaim::KIND_CAC,
                'reference_token' => $cac['token'],
                'reference_last4' => $cac['last4'],
                'display_name_returned' => $enterprise->trading_name,
                'status' => IdentityClaim::STATUS_VERIFIED,
                'verifier' => 'cac',
                'verified_at' => now()->subMinutes(mt_rand(5, 400)),
                'captured_by' => $officer->id,
                'client_uuid' => (string) Str::uuid7(),
            ]);
        }

        // A fabricated day does not survive an identity check either.
        if ($fabricated || $n % 2 !== 0) {
            return;
        }

        $nin = $hasher->hash(IdentityClaim::KIND_NIN, str_pad((string) (10_000_000_000 + ($n * 37)), 11, '0', STR_PAD_LEFT));

        IdentityClaim::query()->create([
            'claimable_type' => $enterprise->getMorphClass(),
            'claimable_id' => $enterprise->id,
            'kind' => IdentityClaim::KIND_NIN,
            'reference_token' => $nin['token'],
            'reference_last4' => $nin['last4'],
            'status' => IdentityClaim::STATUS_VERIFIED,
            'verifier' => 'nimc',
            'verified_at' => now()->subMinutes(mt_rand(5, 400)),
            'captured_by' => $officer->id,
            'client_uuid' => (string) Str::uuid7(),
        ]);
    }

    /**
     * One generated frame, saying plainly what it is.
     *
     * @param  array{lon: float, lat: float, at: Carbon, accuracy: float, satellites: int, mock: bool, network: array{float, float}}  $fix
     */
    private function frame(string $kind, string $subject, array $fix, User $officer): string
    {
        $width = 1280;
        $height = 960;
        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            return '';
        }

        // A quiet ground with a horizon, so a contact sheet of these reads as a
        // sheet of photographs rather than a sheet of error states.
        $ground = imagecolorallocate($image, 32, 40, 50);
        $sky = imagecolorallocate($image, 54, 66, 80);
        $rule = imagecolorallocate($image, 88, 102, 116);
        $ink = imagecolorallocate($image, 226, 224, 217);
        $gold = imagecolorallocate($image, 208, 174, 99);

        imagefilledrectangle($image, 0, 0, $width, $height, $ground);
        imagefilledrectangle($image, 0, 0, $width, (int) ($height * 0.62), $sky);
        imageline($image, 0, (int) ($height * 0.62), $width, (int) ($height * 0.62), $rule);

        // A frame, so the label never sits on the very edge of the paper.
        imagerectangle($image, 40, 40, $width - 40, $height - 40, $rule);

        $font = 5;
        imagestring($image, $font, 70, 80, 'GEOVERIFY DEMO IMAGE', $gold);
        imagestring($image, $font, 70, 110, 'GENERATED, NOT A PHOTOGRAPH', $rule);

        imagestring($image, $font, 70, (int) ($height * 0.68), mb_strtoupper($kind), $gold);
        imagestring($image, $font, 70, (int) ($height * 0.68) + 34, mb_substr($subject, 0, 46), $ink);
        imagestring($image, $font, 70, (int) ($height * 0.68) + 68, $officer->name, $rule);
        imagestring(
            $image,
            $font,
            70,
            (int) ($height * 0.68) + 96,
            $fix['at']->format('Y-m-d H:i').'  '.number_format($fix['lat'], 5).', '.number_format($fix['lon'], 5),
            $rule,
        );

        ob_start();
        imagejpeg($image, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * Score everything, then decide some of it.
     *
     * Left partly undecided on purpose: a review queue with nothing in it proves
     * nothing, and a coverage map with nothing accepted cannot show the
     * difference between visited and verified that it exists to show.
     *
     * @param  list<StructureObservation>  $observations
     */
    private function decide(array $observations, User $supervisor): void
    {
        $score = app(ScoreObservation::class);
        $review = app(ReviewObservation::class);

        foreach ($observations as $index => $observation) {
            $confidence = $score($observation->refresh());

            // Every third good capture is accepted and one weak one is sent
            // back, so both numerators on the coverage map have something in
            // them and the officer's board has work waiting on it.
            if ($confidence >= 70 && $index % 3 === 0) {
                $review($observation->refresh(), ReviewDecision::Accept, $supervisor);

                continue;
            }

            if ($confidence < 55 && $index % 5 === 0) {
                $review(
                    $observation->refresh(),
                    ReviewDecision::Return,
                    $supervisor,
                    'The trace does not show a walk to this building. Walk the street and capture again.',
                );
            }
        }
    }
}
