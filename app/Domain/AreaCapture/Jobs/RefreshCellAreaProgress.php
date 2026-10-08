<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Recounts the live area features touching one cell.
 *
 * Beside RefreshCellProgress, never inside it: that job's building count and
 * completion percentage are measured against footprints, and land is not.
 */
final class RefreshCellAreaProgress implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private readonly int $gridCellId) {}

    public function uniqueId(): string
    {
        return (string) $this->gridCellId;
    }

    public function handle(): void
    {
        DB::statement(<<<'SQL'
            UPDATE grid_cells g
               SET area_features_count = c.n, updated_at = now()
              FROM (
                    SELECT count(*) AS n
                      FROM area_feature_cells afc
                      JOIN area_features f ON f.id = afc.area_feature_id AND f.status = 'live'
                     WHERE afc.grid_cell_id = ?
                   ) c
             WHERE g.id = ? AND g.area_features_count IS DISTINCT FROM c.n
        SQL, [$this->gridCellId, $this->gridCellId]);
    }
}
