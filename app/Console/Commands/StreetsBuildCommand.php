<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Imagery\StreetMaps;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cuts each mandate's offline street map out of the national basemap.
 *
 * Run after geoverify:basemap-fetch (which calls it) and after a mandate is
 * created. A phone holding an older cut sees the checksum change and fetches
 * the new one.
 */
final class StreetsBuildCommand extends Command
{
    protected $signature = 'geoverify:streets-build
        {area? : A coverage area id. Defaults to every mandate}';

    protected $description = 'Cut the offline street map for each mandate from the national basemap';

    public function handle(StreetMaps $streets): int
    {
        $area = $this->argument('area');
        $areas = CoverageArea::query()
            ->when(is_string($area) && $area !== '', fn ($q) => $q->whereKey((int) $area))
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($areas->isEmpty()) {
            $this->components->warn('No mandate to cut a street map for.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($areas as $mandate) {
            try {
                $meta = $streets->build($mandate->id);
                $this->components->twoColumnDetail($mandate->name, number_format($meta['bytes'] / 1_048_576, 1).' MB');
            } catch (Throwable $e) {
                $failed++;
                $this->components->error("{$mandate->name}: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
