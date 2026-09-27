<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Media\Models\Media;
use App\Domain\Registry\Models\Enterprise;
use Illuminate\Support\Carbon;
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

    /** The filters baseQuery takes, all off, for callers that set none of them. */
    /** Pins and density on the directory map: about 0.7 km² a cell. */
    public const MAP_RESOLUTION = 8;

    /** A density cell with fewer than this many businesses is not drawn. */
    public const MAP_FLOOR = 3;

    private const NO_FILTERS = [
        'ward' => null,
        'has_ward' => false,
        'verified_only' => false,
        'with_photos' => false,
        'with_products' => false,
        'enterprise_type' => Enterprise::class,
    ];

    public function __construct(private readonly ResolveListingTier $tiers) {}

    /**
     * @return array{results: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function run(
        string $term = '',
        ?string $sector = null,
        ?string $lga = null,
        int $page = 1,
        ?string $ward = null,
        bool $verifiedOnly = false,
        bool $withPhotos = false,
        bool $withProducts = false,
        string $sort = 'relevance',
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
            'ward' => $ward,
            'has_ward' => $ward !== null && $ward !== '',
            'verified_only' => $verifiedOnly,
            'with_photos' => $withPhotos,
            'with_products' => $withProducts,
            'enterprise_type' => (new Enterprise)->getMorphClass(),
        ];

        // The orders a reader can ask for. Nearest first needs a position
        // for every row, and an unclaimed row has none it may show, so the
        // directory does not offer it.
        $order = match ($sort) {
            'name' => 'trading_name ASC',
            'newest' => 'established_at DESC, trading_name ASC',
            default => 'CASE WHEN :has_term2 THEN similarity(trading_name, :term2) ELSE 0 END DESC, depth_rank ASC, trading_name ASC',
        };

        $total = (int) DB::selectOne(
            'SELECT count(*) AS n FROM ('.$this->baseQuery().') AS d',
            $bindings,
        )->n;

        $rows = DB::select(
            $this->baseQuery().'
            ORDER BY '.$order.'
            LIMIT :limit OFFSET :offset',
            $bindings + ($order === 'trading_name ASC' || str_starts_with($order, 'established_at') ? [] : [
                'term2' => $term,
                'has_term2' => $term !== '',
            ]) + [
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
                latest.signage_observed     AS signage_observed,
                s.h3_index                  AS h3_index
            '.DirectoryVisibility::FROM.'
            WHERE '.DirectoryVisibility::WHERE.'
              AND (NOT :has_term OR e.trading_name % :term)
              AND (NOT :has_sector OR e.sector_code = :sector)
              AND (NOT :has_lga OR lga.name = :lga)
              AND (NOT :has_ward OR ward.name = :ward)
              AND (NOT :verified_only OR (
                    pb.id IS NOT NULL AND e.publication_state = \'opted_in\'
                    AND s.origin = \'field\' AND s.status <> \'rejected\'
              ))
              AND (NOT :with_photos OR (
                    pb.id IS NOT NULL AND e.publication_state = \'opted_in\'
                    AND EXISTS (
                        SELECT 1 FROM media m
                        WHERE m.mediable_type = :enterprise_type
                          AND m.mediable_id = e.id
                          AND m.kind = \'storefront\'
                          AND m.uploaded_by_party_id IS NOT NULL
                          AND m.status = \'stored\'
                    )
              ))
              AND (NOT :with_products OR (
                    pb.id IS NOT NULL AND e.publication_state = \'opted_in\'
                    AND EXISTS (SELECT 1 FROM products p WHERE p.enterprise_id = e.id AND p.status = \'active\')
              ))
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
                ...self::NO_FILTERS,
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

    /**
     * What a listing sells, at its own depth.
     *
     * Only a claimed and published listing shows a catalogue: a reduced row
     * is a business that published its name on the street and nothing else,
     * and a catalogue is the owner's statement. Taken as the row the list
     * already resolved, so the depth can never be read differently here.
     *
     * The projection is fixed: name, unit, price, description and the
     * business's own product photographs, asked for by kind and party author.
     *
     * @param  array<string, mixed>  $row
     * @return list<array{id: int, name: string, unit: string|null, priceNaira: int|null, description: string|null, photos: list<array{url: string}>}>
     */
    public function productsFor(array $row): array
    {
        if (($row['depth'] ?? 'reduced') === 'reduced') {
            return [];
        }

        return Product::query()
            ->where('enterprise_id', (int) $row['id'])
            ->where('status', Product::STATUS_ACTIVE)
            ->with('photos')
            ->orderBy('position')
            ->limit(60)
            ->get()
            ->map(static fn (Product $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'unit' => $p->unit,
                'priceNaira' => $p->price_minor === null ? null : intdiv($p->price_minor, 100),
                'description' => $p->description,
                'photos' => $p->photos->map(static fn (Media $m): array => ['url' => $m->temporaryUrl(30)])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * One business as the directory shows it, or null if it does not.
     *
     * Through the list's own search, so a listing page, and anything sold from
     * one, can never resolve a business the list would have withheld.
     *
     * @return array<string, mixed>|null
     */
    public function listing(Enterprise $enterprise): ?array
    {
        $match = collect($this->run(term: $enterprise->trading_name)['results'])
            ->firstWhere('id', $enterprise->id);

        return is_array($match) ? $match : null;
    }

    /**
     * The map beside the list, over exactly the population the list shows.
     *
     * Two layers, each bounded by the directory's rules:
     *
     *   density  every visible business, counted per H3 cell at resolution 8
     *            (about 0.7 km²), and a cell with fewer than three is not drawn,
     *            so the shading can never point at one shop. An aggregate, as
     *            the ward breakdown is.
     *   pins     claimed and published businesses only, each at the centre of
     *            its resolution 8 cell. An unclaimed business may show its
     *            name, sector, ward and LGA and nothing else, so it has no pin.
     *            Nobody gets an exact position.
     *
     * @param  array{term?: string, sector?: string|null, lga?: string|null, ward?: string|null, verifiedOnly?: bool, withPhotos?: bool, withProducts?: bool}  $filters
     * @return array<string, mixed>
     */
    public function mapFor(array $filters): array
    {
        DB::statement('SELECT set_limit(?)', [self::SIMILARITY_FLOOR]);

        $term = trim($filters['term'] ?? '');
        $bindings = [
            'term' => $term,
            'has_term' => $term !== '',
            'sector' => $filters['sector'] ?? null,
            'has_sector' => ($filters['sector'] ?? null) !== null && $filters['sector'] !== '',
            'lga' => $filters['lga'] ?? null,
            'has_lga' => ($filters['lga'] ?? null) !== null && $filters['lga'] !== '',
            'ward' => $filters['ward'] ?? null,
            'has_ward' => ($filters['ward'] ?? null) !== null && $filters['ward'] !== '',
            'verified_only' => $filters['verifiedOnly'] ?? false,
            'with_photos' => $filters['withPhotos'] ?? false,
            'with_products' => $filters['withProducts'] ?? false,
            'enterprise_type' => (new Enterprise)->getMorphClass(),
        ];

        $density = DB::select('
            SELECT cell::text AS cell, n, ST_AsGeoJSON(h3_cell_to_boundary_geometry(cell)) AS geometry
            FROM (
                SELECT h3_cell_to_parent(h3_index::h3index, '.self::MAP_RESOLUTION.') AS cell, count(*) AS n
                FROM ('.$this->baseQuery().') d
                WHERE h3_index IS NOT NULL
                GROUP BY 1
                HAVING count(*) >= '.self::MAP_FLOOR.'
            ) cells
        ', $bindings);

        $pins = DB::select('
            SELECT enterprise_id, trading_name, sector_name, ward, origin, structure_status, established_at,
                   h3_cell_to_parent(h3_index::h3index, '.self::MAP_RESOLUTION.')::text AS cell,
                   ST_X(h3_cell_to_geometry(h3_cell_to_parent(h3_index::h3index, '.self::MAP_RESOLUTION.'))) AS lng,
                   ST_Y(h3_cell_to_geometry(h3_cell_to_parent(h3_index::h3index, '.self::MAP_RESOLUTION.'))) AS lat
            FROM ('.$this->baseQuery().') d
            WHERE depth_rank = 1 AND h3_index IS NOT NULL
            ORDER BY trading_name
            LIMIT 400
        ', $bindings);

        $extent = DB::selectOne('
            SELECT ST_XMin(x) AS w, ST_YMin(x) AS s, ST_XMax(x) AS e, ST_YMax(x) AS n
            FROM (
                SELECT ST_Extent(h3_cell_to_boundary_geometry(h3_cell_to_parent(h3_index::h3index, 7))) AS x
                FROM ('.$this->baseQuery().') d
                WHERE h3_index IS NOT NULL
            ) e
        ', $bindings);

        return [
            'density' => [
                'type' => 'FeatureCollection',
                'features' => array_map(static fn (object $r): array => [
                    'type' => 'Feature',
                    'geometry' => json_decode((string) $r->geometry, true),
                    'properties' => ['count' => (int) $r->n],
                ], $density),
            ],
            'pins' => array_map(function (object $r): array {
                $listed = $this->tiers->forOrigin((string) $r->origin, (string) $r->structure_status) === 'listed';
                $fresh = $this->tiers->freshness(Carbon::parse((string) $r->established_at))['state'];

                return [
                    'id' => (int) $r->enterprise_id,
                    'name' => (string) $r->trading_name,
                    'sector' => $r->sector_name === null ? null : (string) $r->sector_name,
                    'ward' => $r->ward === null ? null : (string) $r->ward,
                    'cell' => (string) $r->cell,
                    'lng' => round((float) $r->lng, 5),
                    'lat' => round((float) $r->lat, 5),
                    'state' => $listed ? 'published' : ($fresh === 'current' ? 'verified' : 'due'),
                ];
            }, $pins),
            'bounds' => $extent === null || $extent->w === null
                ? null
                : [[(float) $extent->w, (float) $extent->s], [(float) $extent->e, (float) $extent->n]],
            'resolution' => self::MAP_RESOLUTION,
            'floor' => self::MAP_FLOOR,
        ];
    }

    /**
     * Where a reader can search: the wards and local governments the
     * directory holds anything in. Names the rows already show, nothing more.
     *
     * @return list<array{ward: string, lga: string|null}>
     */
    public function places(): array
    {
        return array_map(static fn (object $r): array => [
            'ward' => (string) $r->ward,
            'lga' => $r->lga === null ? null : (string) $r->lga,
        ], DB::select('
            SELECT DISTINCT ward.name AS ward, lga.name AS lga
            '.DirectoryVisibility::FROM.'
            WHERE '.DirectoryVisibility::WHERE.' AND ward.name IS NOT NULL
            ORDER BY ward.name
        '));
    }
}
