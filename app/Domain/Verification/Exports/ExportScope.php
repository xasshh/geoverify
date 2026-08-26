<?php

declare(strict_types=1);

namespace App\Domain\Verification\Exports;

use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Coverage\Models\GridCell;
use App\Domain\Registry\Models\Structure;

/**
 * What an export covers, and what it is allowed to contain.
 *
 * Deliberately explicit about acceptance. A client paying for a register is
 * buying the records a supervisor has accepted, not everything a handset ever
 * sent, and an export that quietly mixes the two is the kind of thing that gets
 * discovered in an audit rather than in a review. The scope is named in the
 * filename and written into the log, so what was handed over is answerable
 * afterwards without opening the file.
 */
final readonly class ExportScope
{
    public function __construct(
        public CoverageArea $area,
        public ?GridCell $cell = null,
        /** Everything captured, not only what survived review. */
        public bool $includeUnaccepted = false,
    ) {}

    /** The statuses a row must hold to appear. */
    /** @return list<string> */
    public function statuses(): array
    {
        return $this->includeUnaccepted
            ? [
                Structure::STATUS_SUBMITTED,
                Structure::STATUS_ACCEPTED,
                Structure::STATUS_FLAGGED,
                Structure::STATUS_REJECTED,
            ]
            : [Structure::STATUS_ACCEPTED];
    }

    /** A filename that says what is inside it without being opened. */
    public function filename(string $extension): string
    {
        $parts = [
            'geoverify',
            str($this->area->name)->slug()->value(),
            $this->cell === null ? 'mandate' : 'cell-'.$this->cell->h3(),
            $this->includeUnaccepted ? 'all-records' : 'accepted',
            now()->format('Ymd-His'),
        ];

        return implode('_', array_filter($parts)).'.'.$extension;
    }

    /** How the scope reads in the log and on screen. */
    public function label(): string
    {
        return sprintf(
            '%s / %s / %s',
            $this->area->name,
            $this->cell === null ? 'whole mandate' : 'cell '.$this->cell->h3(),
            $this->includeUnaccepted ? 'all records' : 'accepted only',
        );
    }

    /** @return array<string, mixed> */
    public function toEvidence(): array
    {
        return [
            'coverage_area_id' => $this->area->id,
            'coverage_area' => $this->area->name,
            'grid_cell_id' => $this->cell?->id,
            'h3' => $this->cell?->h3(),
            'accepted_only' => ! $this->includeUnaccepted,
        ];
    }
}
