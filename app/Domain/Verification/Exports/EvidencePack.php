<?php

declare(strict_types=1);

namespace App\Domain\Verification\Exports;

use App\Domain\Coverage\Models\GridCell;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Everything the evidence pack prints, for one cell.
 *
 * The pack is the document a client's auditor holds, so it has to answer the
 * questions an auditor asks in the order they ask them: what ground is this,
 * who walked it and when, what did they find, what does it look like, and what
 * has happened to each record since. The last of those is the audit log, and it
 * is the reason the whole system keeps one.
 *
 * The map is drawn by PostGIS with ST_AsSVG. Nothing is projected in PHP, not
 * even to fit a viewport: the extent comes back from ST_Extent and the path
 * data comes back ready to place in an SVG element.
 */
final class EvidencePack
{
    /** A contact sheet prints four to a row. Anything larger is not read. */
    private const MAX_EMBEDDED_BYTES = 2_000_000;

    /**
     * @return array<string, mixed>
     */
    public function __invoke(GridCell $cell, User $preparedBy, bool $includeUnaccepted = false): array
    {
        $statuses = $includeUnaccepted
            ? [Structure::STATUS_SUBMITTED, Structure::STATUS_ACCEPTED, Structure::STATUS_FLAGGED, Structure::STATUS_REJECTED]
            : [Structure::STATUS_ACCEPTED];

        return [
            'cell' => $this->cell($cell),
            'summary' => $this->summary($cell, $statuses),
            'map' => $this->map($cell),
            'records' => $this->records($cell, $statuses),
            'photographs' => $this->photographs($cell, $statuses),
            'audit' => $this->audit($cell),
            'preparedBy' => $preparedBy->name,
            'preparedAt' => now()->toIso8601String(),
            'acceptedOnly' => ! $includeUnaccepted,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cell(GridCell $cell): array
    {
        $row = DB::selectOne(<<<'SQL'
            select
                to_hex(cells.h3_index) as h3,
                cells.footprint_count,
                cells.structures_captured,
                cells.structures_accepted,
                cells.status,
                areas.name as area,
                areas.client_name,
                areas.contract_ref,
                ward.name as ward,
                lga.name as lga,
                round(st_area(cells.boundary::geography)::numeric / 10000, 2) as hectares
            from grid_cells cells
            join coverage_areas areas on areas.id = cells.coverage_area_id
            left join admin_boundaries lga on lga.id = areas.admin_boundary_id
            left join lateral (
                select b.name from admin_boundaries b
                 where b.level = 'ward' and st_intersects(b.boundary, cells.boundary)
                 order by st_area(st_intersection(b.boundary, cells.boundary)) desc
                 limit 1
            ) ward on true
            where cells.id = ?
        SQL, [$cell->id]);

        return [
            'h3' => $row?->h3,
            'area' => $row?->area,
            'client' => $row?->client_name,
            'contractRef' => $row?->contract_ref,
            'ward' => $row?->ward,
            'lga' => $row?->lga,
            'hectares' => $row?->hectares,
            'footprints' => (int) ($row->footprint_count ?? 0),
            'captured' => (int) ($row->structures_captured ?? 0),
            'accepted' => (int) ($row->structures_accepted ?? 0),
            'status' => $row?->status,
        ];
    }

    /**
     * Who worked this ground, when, and how far they walked doing it.
     *
     * @param  list<string>  $statuses
     * @return array<string, mixed>
     */
    private function summary(GridCell $cell, array $statuses): array
    {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        $row = DB::selectOne(<<<SQL
            select
                count(distinct structures.id) as structures,
                count(distinct enterprises.id) as enterprises,
                count(distinct structures.captured_by) as officers,
                min(structures.captured_at) as first_capture,
                max(structures.captured_at) as last_capture,
                round(avg(structures.confidence_score)) as mean_confidence
            from structures
            left join enterprises on enterprises.structure_id = structures.id
            where structures.grid_cell_id = ?
              and structures.status in ({$placeholders})
        SQL, [$cell->id, ...$statuses]);

        $walk = DB::selectOne(<<<'SQL'
            select
                coalesce(sum(sessions.distance_m), 0) as distance_m,
                coalesce(sum(sessions.fix_count), 0) as fixes,
                count(*) as sessions
            from field_sessions sessions
            join assignments on assignments.id = sessions.assignment_id
            where assignments.grid_cell_id = ?
        SQL, [$cell->id]);

        return [
            'structures' => (int) ($row->structures ?? 0),
            'enterprises' => (int) ($row->enterprises ?? 0),
            'officers' => (int) ($row->officers ?? 0),
            'firstCapture' => $row?->first_capture,
            'lastCapture' => $row?->last_capture,
            'meanConfidence' => $row?->mean_confidence === null ? null : (int) $row->mean_confidence,
            'distanceM' => (int) ($walk->distance_m ?? 0),
            'fixes' => (int) ($walk->fixes ?? 0),
            'sessions' => (int) ($walk->sessions ?? 0),
        ];
    }

    /**
     * The cell, the walk and the captures, as SVG path data from PostGIS.
     *
     * ST_AsSVG negates Y, because SVG counts downwards and the world does not.
     * The viewBox is therefore built from the negated extent, and no coordinate
     * is touched between the database and the page.
     *
     * @return array<string, mixed>
     */
    private function map(GridCell $cell): array
    {
        $row = DB::selectOne(<<<'SQL'
            with box as (
                select st_expand(st_envelope(boundary), st_perimeter(boundary::geography) / 400000.0) as extent
                  from grid_cells where id = ?
            )
            select
                st_assvg(cells.boundary, 0, 8) as cell_path,
                st_xmin(box.extent) as min_x,
                st_xmax(box.extent) as max_x,
                st_ymin(box.extent) as min_y,
                st_ymax(box.extent) as max_y,
                (
                    select string_agg(st_assvg(st_force2d(sessions.trace), 0, 8), '|')
                      from field_sessions sessions
                      join assignments on assignments.id = sessions.assignment_id
                     where assignments.grid_cell_id = cells.id and sessions.trace is not null
                ) as trace_paths,
                (
                    select string_agg(
                        st_x(structures.centroid::geometry) || ',' || (-st_y(structures.centroid::geometry)),
                        '|'
                    )
                      from structures where structures.grid_cell_id = cells.id
                ) as capture_points
            from grid_cells cells, box
            where cells.id = ?
        SQL, [$cell->id, $cell->id]);

        if ($row === null) {
            return ['viewBox' => '0 0 1 1', 'cell' => '', 'traces' => [], 'captures' => []];
        }

        $minX = (float) $row->min_x;
        $maxX = (float) $row->max_x;
        $minY = (float) $row->min_y;
        $maxY = (float) $row->max_y;

        return [
            // Y is negated to match ST_AsSVG, so the top of the box is -maxY.
            'viewBox' => sprintf(
                '%.8F %.8F %.8F %.8F',
                $minX,
                -$maxY,
                max($maxX - $minX, 1.0E-8),
                max($maxY - $minY, 1.0E-8),
            ),
            'strokeWidth' => max(($maxX - $minX) / 400, 1.0E-8),
            'cell' => (string) ($row->cell_path ?? ''),
            'traces' => $row->trace_paths === null
                ? []
                : array_values(array_filter(explode('|', (string) $row->trace_paths))),
            'captures' => $row->capture_points === null
                ? []
                : array_map(
                    static fn (string $pair): array => array_map(floatval(...), explode(',', $pair)),
                    array_values(array_filter(explode('|', (string) $row->capture_points))),
                ),
        ];
    }

    /**
     * @param  list<string>  $statuses
     * @return list<array<string, mixed>>
     */
    private function records(GridCell $cell, array $statuses): array
    {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        $rows = DB::select(<<<SQL
            select
                structures.id,
                structures.structure_type,
                structures.occupancy_status,
                structures.floors,
                structures.unit_count,
                structures.status,
                structures.confidence_score,
                structures.plus_code,
                round(st_x(structures.centroid::geometry)::numeric, 6) as longitude,
                round(st_y(structures.centroid::geometry)::numeric, 6) as latitude,
                officer.name as officer,
                structures.captured_at,
                (
                    select string_agg(trading_name || coalesce(' (' || sector_code || ')', ''), '; ' order by id)
                      from enterprises where structure_id = structures.id
                ) as businesses
            from structures
            join users officer on officer.id = structures.captured_by
            where structures.grid_cell_id = ?
              and structures.status in ({$placeholders})
            order by structures.captured_at, structures.id
        SQL, [$cell->id, ...$statuses]);

        return array_map(static fn (object $row): array => (array) $row, $rows);
    }

    /**
     * The contact sheet.
     *
     * The path is carried so the sheet can show the photograph where the file
     * is present, and say what is missing where it is not. A pack that silently
     * drops a photograph is worse than one that prints a gap with a filename
     * against it.
     *
     * @param  list<string>  $statuses
     * @return list<array<string, mixed>>
     */
    private function photographs(GridCell $cell, array $statuses): array
    {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        $rows = DB::select(<<<SQL
            select
                media.id,
                media.kind,
                media.disk,
                media.disk_path,
                media.from_device_camera,
                media.distance_from_subject_m,
                media.captured_at,
                media.sha256,
                media.thumb_path,
                coalesce(enterprises.trading_name, structures.structure_type) as subject
            from media
            left join structures
                   on media.mediable_type = ? and media.mediable_id = structures.id
            left join enterprises
                   on media.mediable_type = ? and media.mediable_id = enterprises.id
            left join structures enterprise_structure
                   on enterprise_structure.id = enterprises.structure_id
            where coalesce(structures.grid_cell_id, enterprise_structure.grid_cell_id) = ?
              and coalesce(structures.status, enterprise_structure.status) in ({$placeholders})
            order by media.captured_at, media.id
        SQL, [
            (new Structure)->getMorphClass(),
            (new Enterprise)->getMorphClass(),
            $cell->id,
            ...$statuses,
        ]);

        return array_map(function (object $row): array {
            $shot = (array) $row;
            $shot['data_uri'] = $this->thumbnail($row);

            return $shot;
        }, $rows);
    }

    /**
     * The photograph itself, small enough to embed.
     *
     * Embedded rather than linked so the pack is one file that renders the same
     * in a year as it does today. The thumbnail is preferred over the original
     * because a contact sheet prints four to a row and a full frame photograph
     * would put twenty megabytes into a document nobody will zoom into.
     *
     * A file that is missing prints as a gap with its path against it. A pack
     * that silently drops a photograph is worse than one that admits to a hole.
     */
    private function thumbnail(object $row): ?string
    {
        $disk = Storage::disk((string) ($row->disk ?: 'media'));

        foreach ([$row->thumb_path ?? null, $row->disk_path ?? null] as $path) {
            if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
                continue;
            }

            if ($disk->size($path) > self::MAX_EMBEDDED_BYTES) {
                continue;
            }

            $mime = $disk->mimeType($path) ?: 'image/jpeg';

            return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($path));
        }

        return null;
    }

    /**
     * Everything that has happened to the records in this cell.
     *
     * This is the part of the pack that is the product. A register is only worth
     * what its history is worth, and a client's auditor is entitled to see every
     * transition rather than the current state and a promise.
     *
     * @return list<array<string, mixed>>
     */
    private function audit(GridCell $cell): array
    {
        $rows = DB::select(<<<'SQL'
            select
                events.occurred_at,
                events.event,
                events.actor_type,
                events.actor_label,
                events.evidence,
                events.subject_type,
                events.subject_id
            from verification_events events
            where (events.subject_type = ? and events.subject_id in (
                       select id from structures where grid_cell_id = ?
                   ))
               or (events.subject_type = ? and events.subject_id in (
                       select observations.id from structure_observations observations
                         join structures on structures.id = observations.structure_id
                        where structures.grid_cell_id = ?
                   ))
            order by events.occurred_at, events.id
        SQL, [
            (new Structure)->getMorphClass(),
            $cell->id,
            (new StructureObservation)->getMorphClass(),
            $cell->id,
        ]);

        return array_map(static function (object $row): array {
            $evidence = $row->evidence === null
                ? []
                : (array) json_decode((string) $row->evidence, true, 512, JSON_THROW_ON_ERROR);

            return [
                'occurredAt' => $row->occurred_at,
                'event' => $row->event,
                'actor' => $row->actor_label ?? $row->actor_type,
                'subject' => class_basename((string) $row->subject_type).' #'.$row->subject_id,
                'detail' => $evidence,
                'detail_summary' => self::summarise($evidence),
            ];
        }, $rows);
    }

    /**
     * One line of evidence, for a table cell rather than a debugger.
     *
     * The full array is kept on the record; this is what a reader of the printed
     * log actually needs, which is the number or the reason and not the shape of
     * the payload.
     *
     * @param  array<string, mixed>  $evidence
     */
    private static function summarise(array $evidence): string
    {
        $parts = [];

        foreach (['score', 'confidence_score'] as $key) {
            if (isset($evidence[$key]) && is_numeric($evidence[$key])) {
                $parts[] = 'confidence '.$evidence[$key];
                break;
            }
        }

        if (isset($evidence['from'], $evidence['to'])) {
            $parts[] = $evidence['from'].' to '.$evidence['to'];
        }

        if (isset($evidence['reason']) && is_string($evidence['reason'])) {
            $parts[] = $evidence['reason'];
        }

        if (isset($evidence['flags']) && is_array($evidence['flags']) && $evidence['flags'] !== []) {
            $named = [];

            foreach ($evidence['flags'] as $flag) {
                if (is_array($flag) && isset($flag['signal']) && is_string($flag['signal'])) {
                    $named[] = $flag['signal'];
                }
            }

            if ($named !== []) {
                $parts[] = 'flagged: '.implode(', ', $named);
            }
        }

        return implode(" \u{b7} ", $parts);
    }
}
