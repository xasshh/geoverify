<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use App\Domain\Verification\Enums\Verdict;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The review queue: what a supervisor should look at, worst first.
 *
 * Ordered by confidence ascending, because the point of scoring was to put the
 * captures most likely to be wrong in front of the person who can do something
 * about them. A queue ordered by arrival time is a queue that reviews the
 * honest work first and runs out of afternoon before it reaches the rest.
 *
 * Every list here is loaded in a fixed number of queries regardless of page
 * size. A per row trace query would be two hundred round trips for one screen.
 */
final class BuildReviewQueue
{
    /** Vertices kept for a queue sized mark. Enough to read density at 16px. */
    private const QUEUE_TRACE_POINTS = 60;

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, awaiting: int}
     */
    public function __invoke(int $limit = 50, ?int $coverageAreaId = null, ?int $officerId = null): array
    {
        $rows = $this->observations($limit, $coverageAreaId, $officerId);

        if ($rows === []) {
            return ['rows' => [], 'total' => 0, 'awaiting' => $this->awaiting($coverageAreaId, $officerId)];
        }

        $flags = $this->flagsFor(array_column($rows, 'id'));
        $traces = $this->tracesFor(array_values(array_filter(
            array_column($rows, 'field_session_id'),
            static fn (mixed $id): bool => $id !== null,
        )), self::QUEUE_TRACE_POINTS);

        $queue = [];

        foreach ($rows as $row) {
            $sessionId = $row['field_session_id'];

            $queue[] = [
                'id' => (int) $row['id'],
                'score' => $row['confidence_score'] === null ? null : (int) $row['confidence_score'],
                'observedAt' => $this->iso($row['observed_at']),
                'officer' => $row['officer'],
                'officerId' => (int) $row['officer_id'],
                'structureId' => (int) $row['structure_id'],
                'structureType' => $row['structure_type'],
                'plusCode' => $row['plus_code'],
                'h3' => $row['h3'],
                'enterprises' => (int) $row['enterprise_count'],
                'photographs' => (int) $row['photograph_count'],
                'flags' => $flags[(int) $row['id']] ?? [],
                'trace' => $sessionId === null ? [] : ($traces[(int) $sessionId] ?? []),
            ];
        }

        return [
            'rows' => $queue,
            'total' => count($queue),
            'awaiting' => $this->awaiting($coverageAreaId, $officerId),
        ];
    }

    /**
     * A raw timestamp from a database row, made unambiguous for a browser.
     *
     * The column carries no offset, so a browser parses it as its own local
     * time. On a server running UTC and a console open in Lagos that is an hour
     * of error in "last seen", which is exactly the number a supervisor uses to
     * decide whether to ring somebody. Carbon reads it in the application
     * timezone and writes the offset back out.
     */
    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }

    /**
     * Captures still waiting on a decision, worst score first.
     *
     * A score of null sorts last rather than first: an unscored capture is not
     * a suspicious one, it is one the scorer has not reached yet, and putting it
     * at the top would bury the captures that are actually contested.
     *
     * @return list<array<string, mixed>>
     */
    private function observations(int $limit, ?int $coverageAreaId, ?int $officerId): array
    {
        // There is no morph map on this project, so mediable_type holds the
        // fully qualified class name. Bound rather than written out, so a morph
        // map added later moves these with it.
        $bindings = [
            (new Structure)->getMorphClass(),
            (new Enterprise)->getMorphClass(),
            Structure::STATUS_SUBMITTED,
        ];
        $where = '';

        if ($coverageAreaId !== null) {
            $where .= ' and structures.coverage_area_id = ?';
            $bindings[] = $coverageAreaId;
        }

        if ($officerId !== null) {
            $where .= ' and observations.captured_by = ?';
            $bindings[] = $officerId;
        }

        $bindings[] = $limit;

        /** @var list<array<string, mixed>> $rows */
        $rows = array_map(
            static fn (object $row): array => (array) $row,
            DB::select(<<<SQL
                select
                    observations.id,
                    observations.confidence_score,
                    observations.observed_at,
                    observations.structure_type,
                    observations.field_session_id,
                    observations.captured_by as officer_id,
                    users.name as officer,
                    structures.id as structure_id,
                    structures.plus_code,
                    to_hex(cells.h3_index) as h3,
                    (select count(*) from enterprises where structure_id = structures.id) as enterprise_count,
                    (
                        select count(*) from media
                        where (mediable_type = ? and mediable_id = structures.id)
                           or (mediable_type = ? and mediable_id in (
                                   select id from enterprises where structure_id = structures.id
                               ))
                    ) as photograph_count
                from structure_observations observations
                join structures on structures.id = observations.structure_id
                join users on users.id = observations.captured_by
                join grid_cells cells on cells.id = structures.grid_cell_id
                where observations.status = ?{$where}
                order by observations.confidence_score asc nulls last, observations.observed_at asc
                limit ?
            SQL, $bindings),
        );

        return $rows;
    }

    private function awaiting(?int $coverageAreaId, ?int $officerId): int
    {
        $bindings = [Structure::STATUS_SUBMITTED];
        $where = '';

        if ($coverageAreaId !== null) {
            $where .= ' and structures.coverage_area_id = ?';
            $bindings[] = $coverageAreaId;
        }

        if ($officerId !== null) {
            $where .= ' and observations.captured_by = ?';
            $bindings[] = $officerId;
        }

        return (int) DB::scalar(<<<SQL
            select count(*)
            from structure_observations observations
            join structures on structures.id = observations.structure_id
            where observations.status = ?{$where}
        SQL, $bindings);
    }

    /**
     * The flags for a page of observations, in one query.
     *
     * @param  list<mixed>  $observationIds
     * @return array<int, list<array{signal: string, verdict: string, message: string, deduction: int}>>
     */
    private function flagsFor(array $observationIds): array
    {
        if ($observationIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($observationIds), '?'));

        $rows = DB::select(<<<SQL
            select structure_observation_id, signal, verdict, message, deduction
            from observation_signals
            where structure_observation_id in ({$placeholders})
              and verdict in (?, ?)
            order by deduction desc, signal asc
        SQL, [...array_map(intval(...), $observationIds), Verdict::Fail->value, Verdict::Warn->value]);

        $flags = [];

        foreach ($rows as $row) {
            $flags[(int) $row->structure_observation_id][] = [
                'signal' => (string) $row->signal,
                'verdict' => (string) $row->verdict,
                'message' => (string) $row->message,
                'deduction' => (int) $row->deduction,
            ];
        }

        return $flags;
    }

    /**
     * Sampled trace vertices per session, for the Presence Mark.
     *
     * Sampled by taking every Nth vertex, deliberately not ST_Simplify. Douglas
     * Peucker drops the vertices that sit closest to the line between their
     * neighbours, which is exactly the GNSS jitter that tells a walked day from
     * a generated one. Simplifying the trace would smooth the evidence out of
     * the mark and leave a fabricated day looking like an honest one.
     *
     * @param  list<mixed>  $sessionIds
     * @return array<int, list<array{float, float}>>
     */
    public function tracesFor(array $sessionIds, int $keep): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));

        $rows = DB::select(<<<SQL
            with vertices as (
                select
                    dumped.id as session_id,
                    (dumped.dp).path[1] as idx,
                    (dumped.dp).geom as geom
                from (
                    select id, st_dumppoints(trace) as dp
                    from field_sessions
                    where id in ({$placeholders}) and trace is not null
                ) dumped
            ),
            counted as (
                select *, count(*) over (partition by session_id) as total
                from vertices
            )
            select
                session_id,
                json_agg(
                    json_build_array(st_x(geom), st_y(geom)) order by idx
                ) as points
            from counted
            where total <= ?
               or idx % greatest(1, (total / ?)::int) = 0
            group by session_id
        SQL, [...array_map(intval(...), $sessionIds), $keep, $keep]);

        $traces = [];

        foreach ($rows as $row) {
            /** @var list<array{float, float}> $points */
            $points = json_decode((string) $row->points, true, 512, JSON_THROW_ON_ERROR);
            $traces[(int) $row->session_id] = $points;
        }

        return $traces;
    }
}
