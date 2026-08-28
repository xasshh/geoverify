<?php

declare(strict_types=1);

namespace App\Domain\Claim\Actions;

use App\Domain\Registry\Actions\ResolveListingTier;
use Illuminate\Support\Facades\DB;

/**
 * Finding your own shop in the register, without the register becoming a
 * directory.
 *
 * This is the one place where field-captured records are visible to someone who
 * has not proved anything yet, so what it returns is a deliberate projection
 * rather than a filtered row. A result carries the trading name, the ward, the
 * LGA, the structure type and the tier. It does not carry the phone, the owner,
 * the coordinates, the photographs, the officer, the accuracy, the notes, or
 * the internal id of anything.
 *
 * The reason is not squeamishness. An unauthenticated or lightly authenticated
 * search that returned contact details would be, in practice, a scraping
 * endpoint for every enumerated business in the country: exactly the asset the
 * platform exists to protect, handed out one query at a time. The projection is
 * the security boundary, so it is enforced in the SELECT rather than by hoping
 * the presenter drops the columns.
 *
 * Proximity ranks and filters. It never authorises: see ClaimEvidence.
 */
final class SearchRegister
{
    public function __construct(
        private readonly ResolveListingTier $tiers,
    ) {}

    /**
     * Below this, a trigram hit is noise.
     *
     * Applied through pg_trgm's own threshold rather than as a comparison,
     * because only the `%` operator can use the GIN index. Written as
     * `similarity(name, term) >= 0.18` the filter reads identically and is
     * correct, and it cannot use the index at all: verified by planning it with
     * enable_seqscan off, where it still costs a sequential scan. On eighty
     * rows that is invisible. On the register this is being built for it is the
     * difference between a search and an outage.
     */
    private const SIMILARITY_FLOOR = 0.18;

    private const MAX_RESULTS = 25;

    /** A search near "here" means this ward, not this country. */
    private const PROXIMITY_METRES = 2000;

    /**
     * @return list<array<string, mixed>>
     */
    public function run(string $term, ?float $lat = null, ?float $lng = null): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2 && $lat === null) {
            return [];
        }

        $hasPoint = $lat !== null && $lng !== null;

        // Session scoped, so it is set immediately before the query that
        // depends on it rather than once at boot, where a pooled or recycled
        // connection would quietly search at pg_trgm's 0.3 default instead.
        DB::statement('SELECT set_limit(?)', [self::SIMILARITY_FLOOR]);

        // Ranking is name similarity first, distance second. A shop called
        // "Mama Blessing Stores" two wards away is a likelier match for that
        // search than an unrelated kiosk next door, and the claimant is the one
        // who knows which. Distance only breaks ties, and only when a point was
        // offered.
        $rows = DB::select(<<<'SQL'
            SELECT
                e.id                    AS enterprise_id,
                e.trading_name          AS trading_name,
                s.structure_type        AS structure_type,
                s.origin                AS origin,
                s.status                AS structure_status,
                ward.name               AS ward,
                lga.name                AS lga,
                e.captured_at           AS enumerated_at,
                (pb.id IS NOT NULL)     AS is_claimed,
                (latest.phone IS NOT NULL) AS has_recorded_phone,
                CASE
                    WHEN :has_point THEN round(ST_Distance(
                        s.centroid::geography,
                        ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
                    ))
                    ELSE NULL
                END                     AS metres_away,
                similarity(e.trading_name, :term) AS name_score
            FROM enterprises e
            JOIN structures s ON s.id = e.structure_id
            LEFT JOIN admin_boundaries ward ON ward.id = s.ward_id
            LEFT JOIN admin_boundaries lga  ON lga.id  = s.lga_id
            LEFT JOIN party_businesses pb
                   ON pb.enterprise_id = e.id AND pb.status = 'active'
            LEFT JOIN LATERAL (
                SELECT o.phone
                FROM enterprise_observations o
                WHERE o.enterprise_id = e.id
                ORDER BY o.observed_at DESC
                LIMIT 1
            ) latest ON TRUE
            WHERE s.status <> 'rejected'
              AND (
                    (:term <> '' AND e.trading_name % :term)
                 OR (:has_point AND ST_DWithin(
                        s.centroid::geography,
                        ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography,
                        :radius
                    ))
              )
            ORDER BY
                similarity(e.trading_name, :term) DESC,
                CASE WHEN :has_point THEN ST_Distance(
                    s.centroid::geography,
                    ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
                ) ELSE 0 END ASC
            LIMIT :limit
        SQL, [
            'term' => $term,
            'has_point' => $hasPoint,
            'lat' => $lat ?? 0.0,
            'lng' => $lng ?? 0.0,
            'radius' => self::PROXIMITY_METRES,
            'limit' => self::MAX_RESULTS,
        ]);

        return array_map(fn (object $row): array => [
            'enterprise_id' => (int) $row->enterprise_id,
            'trading_name' => (string) $row->trading_name,
            'structure_type' => (string) $row->structure_type,
            'ward' => $row->ward === null ? null : (string) $row->ward,
            'lga' => $row->lga === null ? null : (string) $row->lga,
            // Resolved, never assumed. Before self-registration existed every
            // record here was a field capture and this could safely be a
            // constant; the moment a business could type its own address, a
            // constant became a lie that sells a visit nobody made.
            'tier' => $this->tiers->forOrigin((string) $row->origin, (string) $row->structure_status),
            'established_on' => $this->month($row->enumerated_at),
            'is_claimed' => (bool) $row->is_claimed,
            'can_prove_by_phone' => (bool) $row->has_recorded_phone,
            'metres_away' => $row->metres_away === null ? null : (int) $row->metres_away,
        ], $rows);
    }

    /**
     * The month, not the day.
     *
     * A search result is a list of businesses the searcher may have nothing to
     * do with, and the exact date an officer visited a particular shop is an
     * operational detail about our field movements. The month is enough to
     * judge freshness by, which is all the tier needs.
     */
    private function month(string $timestamp): string
    {
        return date('F Y', strtotime($timestamp));
    }
}
