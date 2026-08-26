<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes how much of a cell has been captured, once per cell rather than
 * once per capture.
 *
 * This used to run inline on every capture, and it was the single most
 * expensive thing the sync endpoint did. Not because the count is slow, but
 * because every capture in a cell wrote to the same grid_cells row: two
 * thousand captures meant two thousand versions of one row, so the row bloated,
 * writers queued behind each other, and reads of it slowed down with them. A
 * primary key lookup on grid_cells was measured at 16 ms.
 *
 * Coalesced by ShouldBeUnique, so a batch of two thousand captures in one cell
 * leaves one pending job instead of two thousand. The count is still recomputed
 * from the table rather than incremented, so running it twice, or late, or after
 * a retry, gives the same answer.
 *
 * The cost is that coverage is eventually consistent. Everything that reads it
 * is a map, a board or an export, and none of them are worth an officer waiting
 * on a lock.
 */
final class RefreshCellProgress implements ShouldBeUnique, ShouldQueue
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
               SET structures_captured = c.n,
                   coverage_pct = CASE WHEN g.footprint_count = 0 THEN 0
                                       ELSE LEAST(100, round((c.n::numeric / g.footprint_count) * 100, 2)) END,
                   updated_at = now()
              FROM (SELECT count(*) AS n FROM structures WHERE grid_cell_id = ?) c
             WHERE g.id = ?
               AND (g.structures_captured, g.coverage_pct) IS DISTINCT FROM (
                   c.n,
                   CASE WHEN g.footprint_count = 0 THEN 0
                        ELSE LEAST(100, round((c.n::numeric / g.footprint_count) * 100, 2)) END
               )
        SQL, [$this->gridCellId, $this->gridCellId]);
    }
}
