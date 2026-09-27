<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Loads ISIC Rev 4 and the Nigerian trade names that point at it.
 *
 * Source is the UN Statistics Division's published structure file. Idempotent, so
 * a re-run refreshes rather than duplicates.
 */
final class TaxonomyLoadCommand extends Command
{
    protected $signature = 'geoverify:taxonomy-load
        {--path= : ISIC Rev 4 structure CSV, defaults to the bundled copy}
        {--skip-aliases : Load ISIC only, leaving the trade alias list alone}';

    protected $description = 'Load the ISIC Rev 4 sector taxonomy and Nigerian trade aliases';

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?? database_path('data/isic_rev4_structure.csv'));

        if (! is_readable($path)) {
            $this->components->error(
                "ISIC structure file not found at {$path}. ".
                'Download it from unstats.un.org, or pass --path.',
            );

            return self::FAILURE;
        }

        $loaded = $this->loadIsic($path);
        $this->components->twoColumnDetail('ISIC entries', number_format($loaded));

        foreach (['section' => 21, 'division' => 88, 'group' => 238, 'class' => 419] as $level => $expected) {
            $actual = (int) DB::scalar('select count(*) from isic_classes where level = ?', [$level]);
            $this->components->twoColumnDetail(
                Str::plural(ucfirst($level)),
                $actual === $expected ? (string) $actual : "<fg=yellow>{$actual}, expected {$expected}</>",
            );
        }

        if (! (bool) $this->option('skip-aliases')) {
            $this->components->twoColumnDetail('Trade aliases', (string) $this->loadAliases());
        }

        return self::SUCCESS;
    }

    /** @var array<string, array{int, int}> */
    public const SECTION_RANGES = [
        'A' => [1, 3], 'B' => [5, 9], 'C' => [10, 33], 'D' => [35, 35], 'E' => [36, 39],
        'F' => [41, 43], 'G' => [45, 47], 'H' => [49, 53], 'I' => [55, 56], 'J' => [58, 63],
        'K' => [64, 66], 'L' => [68, 68], 'M' => [69, 75], 'N' => [77, 82], 'O' => [84, 84],
        'P' => [85, 85], 'Q' => [86, 88], 'R' => [90, 93], 'S' => [94, 96], 'T' => [97, 98],
        'U' => [99, 99],
    ];

    private function loadIsic(string $path): int
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not open {$path}");
        }

        $rows = [];
        fgetcsv($handle, escape: '');

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $code = trim((string) ($row[0] ?? ''));
            $name = trim((string) ($row[1] ?? ''));

            if ($code === '' || $name === '') {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'name' => $name,
                'level' => $this->levelFor($code),
                'parent_code' => $this->parentFor($code),
            ];
        }

        fclose($handle);

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('isic_classes')->upsert(
                array_map(static fn (array $r): array => $r + [
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $chunk),
                ['code'],
                ['name', 'level', 'parent_code', 'updated_at'],
            );
        }

        return count($rows);
    }

    private function loadAliases(): int
    {
        /** @var list<array{term: string, code: string, weight?: int, language?: string}> $aliases */
        $aliases = require database_path('data/nigerian_trade_aliases.php');

        $known = DB::table('isic_classes')->pluck('code')->flip();
        $rows = [];
        $unknown = [];

        foreach ($aliases as $alias) {
            if (! $known->has($alias['code'])) {
                // An alias pointing at a code that is not in ISIC would silently
                // file businesses under nothing, so it is reported rather than
                // written.
                $unknown[] = "{$alias['term']} -> {$alias['code']}";

                continue;
            }

            $rows[] = [
                'term' => $alias['term'],
                'isic_code' => $alias['code'],
                'weight' => $alias['weight'] ?? 100,
                'language' => $alias['language'] ?? 'en',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($unknown !== []) {
            $this->components->warn('Aliases pointing at unknown ISIC codes: '.implode(', ', $unknown));
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('trade_aliases')->upsert($chunk, ['term', 'isic_code'], ['weight', 'language', 'updated_at']);
        }

        return count($rows);
    }

    private function levelFor(string $code): string
    {
        return match (true) {
            ctype_alpha($code) => 'section',
            strlen($code) === 2 => 'division',
            strlen($code) === 3 => 'group',
            default => 'class',
        };
    }

    private function parentFor(string $code): ?string
    {
        // A division's parent is its section, which the structure file does not
        // state, so it comes from the section ranges. Numeric levels nest by
        // prefix.
        return match (true) {
            ctype_alpha($code) => null,
            strlen($code) === 2 => self::sectionOf($code),
            default => substr($code, 0, strlen($code) - 1),
        };
    }

    /**
     * The ISIC Rev. 4 section a two-digit division sits in. Fixed by the
     * standard, so held here rather than read from a file that omits it.
     */
    public static function sectionOf(string $division): ?string
    {
        $n = (int) $division;

        foreach (self::SECTION_RANGES as $section => [$from, $to]) {
            if ($n >= $from && $n <= $to) {
                return $section;
            }
        }

        return null;
    }
}
