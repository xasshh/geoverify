<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Coverage\Actions\LoadAdminBoundaries;
use App\Domain\Coverage\Models\AdminBoundary;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

/**
 * Loads state, LGA and ward geography.
 *
 * Presets exist because the three authoritative Nigerian sources disagree about
 * field names, and getting them wrong silently loads a register keyed to the
 * wrong codes.
 */
final class BoundariesLoadCommand extends Command
{
    protected $signature = 'geoverify:boundaries-load
        {path : Path to a GeoJSON FeatureCollection}
        {--preset= : ocha-state, ocha-lga or grid3-ward}
        {--level= : Override the preset level}
        {--source= : Override the preset source label}
        {--resolve-hierarchy=1 : Link each boundary to its container after loading}';

    protected $description = 'Load administrative boundaries from GeoJSON';

    public function handle(LoadAdminBoundaries $loader): int
    {
        $preset = (string) ($this->option('preset') ?? '');
        $definition = $this->preset($preset);

        if ($definition === null) {
            $this->components->error("Unknown preset '{$preset}'. Use ocha-state, ocha-lga or grid3-ward.");

            return self::FAILURE;
        }

        $level = (string) ($this->option('level') ?? $definition['level']);
        $source = (string) ($this->option('source') ?? $definition['source']);
        $path = (string) $this->argument('path');

        $this->components->info("Loading {$level} boundaries from ".basename($path));

        $bar = null;

        try {
            $result = $loader->load(
                $path,
                $level,
                $source,
                $definition['mapping'],
                function (int $done, int $total) use (&$bar): void {
                    if (! $bar instanceof ProgressBar) {
                        $bar = $this->output->createProgressBar($total);
                        $bar->start();
                    }

                    $bar->setProgress(min($done, $total));
                },
            );
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($bar instanceof ProgressBar) {
            $bar->finish();
            $this->newLine(2);
        }

        $this->components->twoColumnDetail('Loaded', (string) $result['loaded']);

        if ($result['skipped'] > 0) {
            $this->components->twoColumnDetail(
                '<fg=yellow>Skipped</>',
                "{$result['skipped']} (no geometry or no name)",
            );
        }

        if ((string) $this->option('resolve-hierarchy') === '1') {
            $this->resolveHierarchy($loader, $level);
        }

        $this->summarise($level);

        return self::SUCCESS;
    }

    private function resolveHierarchy(LoadAdminBoundaries $loader, string $level): void
    {
        $parentLevel = match ($level) {
            AdminBoundary::LEVEL_LGA => AdminBoundary::LEVEL_STATE,
            AdminBoundary::LEVEL_WARD => AdminBoundary::LEVEL_LGA,
            default => null,
        };

        if ($parentLevel === null) {
            return;
        }

        $linked = $loader->resolveHierarchy($level, $parentLevel);
        $this->components->twoColumnDetail("Linked to {$parentLevel}", (string) $linked);

        $orphans = AdminBoundary::query()
            ->where('level', $level)
            ->whereNull('parent_id')
            ->count();

        if ($orphans > 0) {
            // Worth surfacing rather than swallowing: an orphan means the child sits
            // outside every loaded parent, which is either missing parent data or a
            // genuine boundary disagreement between the two sources.
            $this->components->twoColumnDetail(
                '<fg=yellow>Unlinked</>',
                "{$orphans} {$level} rows fall outside every loaded {$parentLevel}",
            );
        }
    }

    private function summarise(string $level): void
    {
        $count = AdminBoundary::query()->where('level', $level)->count();
        $this->components->twoColumnDetail("Total {$level} boundaries", (string) $count);
    }

    /**
     * Reads a property as a trimmed string. Sources publish nulls and numbers in
     * fields that are nominally text.
     *
     * @param  array<string, mixed>  $properties
     */
    private static function text(array $properties, string $key): string
    {
        $value = $properties[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @return null|array{level: string, source: string, mapping: array{name: string, code: Closure(array<string, mixed>): string, alt: Closure(array<string, mixed>): array<int, string>}}
     */
    private function preset(string $preset): ?array
    {
        return match ($preset) {
            // OCHA Common Operational Datasets. Carries hierarchical pcodes
            // (NG015 state, NG015002 LGA) which are the codes clients quote.
            'ocha-state' => [
                'level' => AdminBoundary::LEVEL_STATE,
                'source' => 'ocha_cod_ab',
                'mapping' => [
                    'name' => 'adm1_name',
                    'code' => static fn (array $p): string => self::text($p, 'adm1_pcode'),
                    'alt' => static fn (array $p): array => [self::text($p, 'adm1_name1'), self::text($p, 'adm1_name2')],
                ],
            ],
            'ocha-lga' => [
                'level' => AdminBoundary::LEVEL_LGA,
                'source' => 'ocha_cod_ab',
                'mapping' => [
                    'name' => 'adm2_name',
                    'code' => static fn (array $p): string => self::text($p, 'adm2_pcode'),
                    'alt' => static fn (array $p): array => [self::text($p, 'adm2_name1'), self::text($p, 'adm2_name2')],
                    'parent' => 'adm1_name',
                ],
            ],
            // GRID3 operational wards. No published ward pcode, so a stable code is
            // derived from state, LGA and ward name: deterministic, so re-running
            // updates the same rows rather than duplicating them.
            'grid3-ward' => [
                'level' => AdminBoundary::LEVEL_WARD,
                'source' => 'grid3_operational_wards_v3',
                'mapping' => [
                    'name' => 'ward',
                    // Never truncated. Shortening this key collides distinct wards
                    // and silently merges them, which puts captures in the wrong ward.
                    'code' => static fn (array $p): string => Str::of(
                        self::text($p, 'statecode').'-'.self::text($p, 'lga').'-'.self::text($p, 'ward')
                    )->slug()->value(),
                    'alt' => static fn (array $p): array => array_map(
                        trim(...),
                        explode(',', self::text($p, 'ward_alt_names')),
                    ),
                    'parent' => 'lga',
                ],
            ],
            default => null,
        };
    }
}
