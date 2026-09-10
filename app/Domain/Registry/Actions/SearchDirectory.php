<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Media\Models\Media;
use Illuminate\Support\Facades\DB;

/**
 * The public directory: what a stranger with no account may see of the register.
 *
 * This is the widest disclosure surface in the system and the only query in it
 * that anybody at all can run. Everything it returns is therefore a decision
 * rather than a convenience, and the decisions are these.
 *
 * **Depth is a property of the record, not of the reader.** Three depths:
 *
 * 1. `reduced` - unclaimed, and only where the latest observation recorded
 *    signage. A business that put its name on the street has published that
 *    much itself; one that did not has published nothing, and does not appear.
 * 2. `claimed` - somebody proved control and opted in. Adds what the owner
 *    chose to say about themselves.
 * 3. `verified` - an officer attended. Adds the tier, the date and the code a
 *    buyer can check.
 *
 * A record that is `withheld` is absent at every depth. Removal is honoured
 * before it is verified, because the cost of wrongly showing a business that
 * asked to be left alone is not symmetrical with the cost of wrongly hiding one.
 *
 * **What never leaves, at any depth, whatever the publication state:** the
 * phone, the email, the exact coordinate, any officer photograph, the officer,
 * the accuracy, the confidence score, the internal id of anything but the
 * enterprise. Those are enforced in the SELECT rather than by dropping columns
 * afterwards, for the same reason the claim search is: a projection that is
 * filtered in PHP is one refactor away from being a scraping endpoint for every
 * enumerated business in the country.
 */
final class SearchDirectory
{
    /** Below this a trigram hit is noise. Same floor as the claim search. */
    private const SIMILARITY_FLOOR = 0.18;

    private const PER_PAGE = 24;

    public function __construct(private readonly ResolveListingTier $tiers) {}

    /**
     * @return array{results: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function run(
        string $term = '',
        ?string $sector = null,
        ?string $lga = null,
        int $page = 1,
    ): array {
        $term = trim($term);
        $page = max(1, $page);

        DB::statement('SELECT set_limit(?)', [self::SIMILARITY_FLOOR]);

        $bindings = [
            'term' => $term,
            'has_term' => $term !== '',
            'sector' => $sector,
            'has_sector' => $sector !== null && $sector !== '',
            'lga' => $lga,
            'has_lga' => $lga !== null && $lga !== '',
        ];

        $total = (int) DB::selectOne(
            'SELECT count(*) AS n FROM ('.$this->baseQuery().') AS d',
            $bindings,
        )->n;

        $rows = DB::select(
            $this->baseQuery().'
            ORDER BY
                CASE WHEN :has_term2 THEN similarity(trading_name, :term2) ELSE 0 END DESC,
                depth_rank ASC,
                trading_name ASC
            LIMIT :limit OFFSET :offset',
            $bindings + [
                'term2' => $term,
                'has_term2' => $term !== '',
                'limit' => self::PER_PAGE,
                'offset' => ($page - 1) * self::PER_PAGE,
            ],
        );

        return [
            'results' => array_map(fn (object $row): array => $this->project($row), $rows),
            'total' => $total,
            'page' => $page,
            'pages' => (int) max(1, (int) ceil($total / self::PER_PAGE)),
        ];
    }

    /**
     * The one SELECT, shared by the count and the page.
     *
     * `withheld` is excluded first and unconditionally. An unclaimed record
     * needs signage to appear at all; a claimed one needs its owner to have
     * opted in. There is no branch in which a private, unsignposted record is
     * returned.
     */
    private function baseQuery(): string
    {
        return '
            SELECT
                e.id                        AS enterprise_id,
                e.trading_name              AS trading_name,
                e.sector_code               AS sector_code,
                isic.name                   AS sector_name,
                s.structure_type            AS structure_type,
                s.origin                    AS origin,
                s.status                    AS structure_status,
                e.captured_at               AS established_at,
                ward.name                   AS ward,
                lga.name                    AS lga,
                (pb.id IS NOT NULL)         AS is_claimed,
                (e.publication_state = \'opted_in\') AS opted_in,
                CASE
                    WHEN pb.id IS NOT NULL AND e.publication_state = \'opted_in\' THEN 1
                    ELSE 2
                END                         AS depth_rank,
                latest.opening_hours        AS opening_hours,
                latest.signage_observed     AS signage_observed
            '.DirectoryVisibility::FROM.'
            WHERE '.DirectoryVisibility::WHERE.'
              AND (NOT :has_term OR e.trading_name % :term)
              AND (NOT :has_sector OR e.sector_code = :sector)
              AND (NOT :has_lga OR lga.name = :lga)
        ';
    }

    /**
     * Other businesses like this one.
     *
     * Same sector, same local government, and never the business being looked
     * at. Ordered so a verified neighbour comes before an unverified one, which
     * is the only ranking here that is about usefulness rather than accident:
     * somebody reading a listing for a shop nobody has checked is well served
     * by being shown one that has been.
     *
     * Runs through the same projection as the list, so a suggestion can never
     * carry a field the directory would have withheld.
     *
     * @return list<array<string, mixed>>
     */
    public function similarTo(int $excludeId, ?string $sector, ?string $lga, int $limit = 4): array
    {
        if ($sector === null && $lga === null) {
            return [];
        }

        DB::statement('SELECT set_limit(?)', [self::SIMILARITY_FLOOR]);

        $rows = DB::select(
            $this->baseQuery().'
              AND e.id <> :exclude
            ORDER BY depth_rank ASC, trading_name ASC
            LIMIT :limit',
            [
                'term' => '',
                'has_term' => false,
                'sector' => $sector,
                'has_sector' => $sector !== null,
                'lga' => $lga,
                'has_lga' => $lga !== null,
                'exclude' => $excludeId,
                'limit' => $limit,
            ],
        );

        return array_map(fn (object $row): array => $this->project($row), $rows);
    }

    /**
     * Sectors a search term might have meant.
     *
     * Reads trade_aliases, which exists for exactly this: "kiosk", "mini mart"
     * and "provisions store" are what people call a shop, and 4711 is what the
     * taxonomy calls it. Somebody searching for a chemist and finding nothing
     * is better served by being pointed at pharmacies than by an empty page.
     *
     * @return list<array{code: string, name: string}>
     */
    public function sectorsMeaning(string $term, int $limit = 3): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 3) {
            return [];
        }

        DB::statement('SELECT set_limit(?)', [self::SIMILARITY_FLOOR]);

        return array_map(static fn (object $row): array => [
            'code' => (string) $row->code,
            'name' => (string) $row->name,
        ], DB::select(<<<'SQL'
            SELECT isic.code AS code, isic.name AS name,
                   max(GREATEST(
                       similarity(a.term, :term),
                       similarity(isic.name, :term)
                   )) AS score
              FROM isic_classes isic
              LEFT JOIN trade_aliases a ON a.isic_code = isic.code
             WHERE a.term % :term OR isic.name % :term
             GROUP BY isic.code, isic.name
             ORDER BY score DESC
             LIMIT :limit
        SQL, ['term' => $term, 'limit' => $limit]));
    }

    /**
     * The photographs a business chose to show.
     *
     * Signed and short lived, like every other file this system serves. The
     * signature is the authorisation, which is what lets a public page carry
     * one at all: there is no session behind a directory reader. Thirty minutes
     * rather than ten, because a directory page is read for longer than a
     * console screen and a photograph that 403s halfway down the page reads as
     * a broken business rather than an expired link.
     *
     * Dimensions are deliberately not returned. StoreMediaFile does not read
     * them on this path, so they would be two null fields on every row of the
     * widest disclosure surface in the system, and a projection that carries
     * fields meaning nothing is a projection nobody reads carefully.
     *
     * @return list<array{url: string}>
     */
    private function photosFor(int $enterpriseId): array
    {
        return Media::query()
            ->where('mediable_type', 'App\\Domain\\Registry\\Models\\Enterprise')
            ->where('mediable_id', $enterpriseId)
            ->where('kind', Media::KIND_STOREFRONT)
            ->whereNotNull('uploaded_by_party_id')
            ->where('status', Media::STATUS_STORED)
            ->orderBy('id')
            ->limit(6)
            ->get()
            ->map(static fn (Media $media): array => [
                'url' => $media->temporaryUrl(30),
            ])
            ->values()
            ->all();
    }

    /**
     * One row, at its own depth.
     *
     * Opening hours are the single field that appears only at `claimed`: an
     * officer records them, but publishing what a shop told an officer at the
     * door is different from a business choosing to publish its hours, and only
     * the second has consent behind it.
     *
     * @return array<string, mixed>
     */
    private function project(object $row): array
    {
        $claimed = (bool) $row->is_claimed && (bool) $row->opted_in;

        $tier = $this->tiers->forOrigin(
            (string) $row->origin,
            (string) $row->structure_status,
        );

        $verified = $tier !== 'listed';

        return [
            'id' => (int) $row->enterprise_id,
            'depth' => $claimed ? ($verified ? 'verified' : 'claimed') : 'reduced',
            'tradingName' => (string) $row->trading_name,
            'sector' => $row->sector_name === null ? null : (string) $row->sector_name,
            'sectorCode' => $row->sector_code === null ? null : (string) $row->sector_code,
            'structureType' => (string) $row->structure_type,
            'ward' => $row->ward === null ? null : (string) $row->ward,
            'lga' => $row->lga === null ? null : (string) $row->lga,
            'tier' => $tier,
            'verified' => $verified,
            'openingHours' => $claimed && $row->opening_hours !== null
                ? (string) $row->opening_hours
                : null,

            // Only what the business took of itself, and only once it has
            // published. An officer's photographs are in the same table and are
            // never asked for here: this SELECT names storefront photographs
            // with a party author rather than asking for everything and
            // dropping the evidence afterwards.
            'photos' => $claimed ? $this->photosFor((int) $row->enterprise_id) : [],
        ];
    }
}
