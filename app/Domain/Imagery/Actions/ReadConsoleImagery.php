<?php

declare(strict_types=1);

namespace App\Domain\Imagery\Actions;

use App\Domain\Imagery\Models\BasemapLayer;

/**
 * The satellite archives a console map can switch to: the current image of
 * each mandate asked for, or of every mandate when none is named.
 *
 * Each is served by the coverage route, behind the same `supervises` gate as
 * the page that lists it, so a link here is no wider than the page itself.
 */
final class ReadConsoleImagery
{
    /** More than this and a "whole programme" view should be choosing a mandate first. */
    private const MOST = 12;

    /**
     * @param  list<int>|null  $areaIds  null for every mandate
     * @return list<array{url: string, captured: string|null, maxZoom: int|null, licence: string|null}>
     */
    public function __invoke(?array $areaIds = null): array
    {
        return BasemapLayer::query()
            ->current()
            ->when($areaIds !== null, fn ($q) => $q->whereIn('coverage_area_id', $areaIds ?? []))
            ->orderByDesc('built_at')
            ->get()
            ->unique('coverage_area_id')
            ->take(self::MOST)
            ->map(static fn (BasemapLayer $layer): array => [
                'url' => route('console.coverage.imagery', $layer->coverage_area_id, absolute: false),
                'captured' => $layer->capturedLabel(),
                'maxZoom' => $layer->max_zoom,
                'licence' => $layer->licence_note,
            ])
            ->values()
            ->all();
    }
}
