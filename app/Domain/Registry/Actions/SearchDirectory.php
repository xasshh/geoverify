<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Commerce\Enums\Protection;
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

    /** Pins and density on the directory map: about 0.7 km² a cell. */
    public const MAP_RESOLUTION = 8;

    /** A density cell with fewer than this many businesses is not drawn. */
    public const MAP_FLOOR = 3;

    public function __construct(private readonly ResolveListingTier $tiers) {}

    /**
     * @param  array{0: float, 1: float}|null  $near  The reader's position, [lng, lat], for "nearest".
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $box  The map on screen, west, south, east, north.
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
        bool $openNow = false,
        bool $payable = false,
        bool $inspection = false,
        bool $delivers = false,
        ?array $near = null,
        ?array $box = null,
    ): array {
        $term = trim($term);
        $page = max(1, $page);

        DB::statement('SELECT set_limit(?)', [self::SIMILARITY_FLOOR]);

        $bindings = $this->bindings([
            'term' => $term,
            'sector' => $sector,
            'lga' => $lga,
            'ward' => $ward,
            'verifiedOnly' => $verifiedOnly,
            'withPhotos' => $withPhotos,
            'withProducts' => $withProducts,
            'openNow' => $openNow,
            'payable' => $payable,
            'inspection' => $inspection,
            'delivers' => $delivers,
            'near' => $near,
            'box' => $box,
        ]);

        // The orders a reader can ask for. Nearest first measures to each
        // published business's cell centre, from the ward searched or the
        // reader's own position; an unclaimed row has no position it may
        // show, so it sorts after every row that does.
        $order = match (true) {
            $sort === 'name' => 'trading_name ASC',
            $sort === 'newest' => 'established_at DESC, trading_name ASC',
            $sort === 'nearest' && $bindings['has_origin'] => 'distance_m ASC NULLS LAST, depth_rank ASC, trading_name ASC',
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
            $bindings + (! str_contains($order, ':term2') ? [] : [
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
    /**
     * Every parameter the one SELECT takes, from one set of filters, so the
     * list, its count and the map can never be asked slightly different
     * questions. Named once per use: PostgreSQL's driver does not reuse a
     * named parameter.
     *
     * @param  array{term?: string, sector?: string|null, lga?: string|null, ward?: string|null, verifiedOnly?: bool, withPhotos?: bool, withProducts?: bool, openNow?: bool, payable?: bool, inspection?: bool, delivers?: bool, near?: array{0: float, 1: float}|null, box?: array{0: float, 1: float, 2: float, 3: float}|null}  $f
     * @return array<string, mixed>
     */
    private function bindings(array $f): array
    {
        $term = trim($f['term'] ?? '');
        $ward = $f['ward'] ?? null;
        $near = $f['near'] ?? null;
        $box = $f['box'] ?? null;
        $inspectionOffered = Protection::Inspection->isOffered();
        $local = Carbon::now('Africa/Lagos');

        // Where "nearest" is measured from: the reader's own position if they
        // gave it, else the middle of the ward they searched.
        if ($near === null && $ward !== null && $ward !== '') {
            $centre = DB::selectOne(
                "SELECT ST_X(ST_PointOnSurface(boundary)) AS lng, ST_Y(ST_PointOnSurface(boundary)) AS lat FROM admin_boundaries WHERE name = ? AND level = 'ward' LIMIT 1",
                [$ward],
            );
            $near = $centre === null ? null : [(float) $centre->lng, (float) $centre->lat];
        }

        return [
            'term' => $term,
            'has_term' => $term !== '',
            'sector' => $f['sector'] ?? null,
            'has_sector' => ($f['sector'] ?? null) !== null && $f['sector'] !== '',
            'lga' => $f['lga'] ?? null,
            'has_lga' => ($f['lga'] ?? null) !== null && $f['lga'] !== '',
            'ward' => $ward,
            'has_ward' => $ward !== null && $ward !== '',
            'verified_only' => $f['verifiedOnly'] ?? false,
            'with_photos' => $f['withPhotos'] ?? false,
            'with_products' => $f['withProducts'] ?? false,
            'enterprise_type' => (new Enterprise)->getMorphClass(),
            'open_now' => $f['openNow'] ?? false,
            'today' => strtolower($local->format('D')),
            'today2' => strtolower($local->format('D')),
            'today3' => strtolower($local->format('D')),
            'now_hm' => $local->format('H:i'),
            'now_hm2' => $local->format('H:i'),
            'today_f' => strtolower($local->format('D')),
            'today_f2' => strtolower($local->format('D')),
            'today_f3' => strtolower($local->format('D')),
            'now_hm_f' => $local->format('H:i'),
            'now_hm_f2' => $local->format('H:i'),
            'payable_only' => ($f['payable'] ?? false) || ($f['inspection'] ?? false),
            'inspection_blocked' => ($f['inspection'] ?? false) && ! $inspectionOffered,
            'delivers_only' => $f['delivers'] ?? false,
            'has_origin' => $near !== null,
            'olng' => $near[0] ?? 0.0,
            'olat' => $near[1] ?? 0.0,
            'has_box' => $box !== null,
            'bw' => $box[0] ?? 0.0,
            'bs' => $box[1] ?? 0.0,
            'be' => $box[2] ?? 0.0,
            'bn' => $box[3] ?? 0.0,
            'bw2' => $box[0] ?? 0.0,
            'bs2' => $box[1] ?? 0.0,
            'be2' => $box[2] ?? 0.0,
            'bn2' => $box[3] ?? 0.0,
        ];
    }

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
                s.h3_index                  AS h3_index,
                -- What the owner states. Read for every row, projected only
                -- for a claimed and published one (see project()).
                bp.weekly_hours::text       AS weekly_hours,
                bp.delivers                 AS delivers,
                bp.street_address           AS street_address,
                h3_cell_to_parent(s.h3_index::h3index, '.self::MAP_RESOLUTION.')::text AS cell,
                EXISTS (SELECT 1 FROM products p WHERE p.enterprise_id = e.id AND p.status = \'active\' AND p.price_minor > 0) AS payable,
                (SELECT round(avg(r.rating)::numeric, 1) FROM reviews r WHERE r.enterprise_id = e.id AND r.status = \'published\') AS rating,
                (SELECT count(*) FROM reviews r WHERE r.enterprise_id = e.id AND r.status = \'published\') AS review_count,
                (SELECT count(*) FROM purchase_orders po WHERE po.enterprise_id = e.id AND po.status = \'released\') AS orders_completed,
                CASE WHEN :has_origin AND pb.id IS NOT NULL AND e.publication_state = \'opted_in\'
                     THEN ST_Distance(
                            ST_SetSRID(ST_MakePoint(:olng, :olat), 4326)::geography,
                            h3_cell_to_geometry(h3_cell_to_parent(s.h3_index::h3index, '.self::MAP_RESOLUTION.'))::geography)
                END                         AS distance_m,
                COALESCE(
                    jsonb_typeof(bp.weekly_hours -> :today) = \'object\'
                    AND :now_hm >= (bp.weekly_hours -> :today2 ->> \'opens\')
                    AND :now_hm2 < (bp.weekly_hours -> :today3 ->> \'closes\'),
                    false)                  AS open_now
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
              -- Owner statements, so they match a published listing only.
              AND (NOT :open_now OR (
                    pb.id IS NOT NULL AND e.publication_state = \'opted_in\'
                    AND jsonb_typeof(bp.weekly_hours -> :today_f) = \'object\'
                    AND :now_hm_f >= (bp.weekly_hours -> :today_f2 ->> \'opens\')
                    AND :now_hm_f2 < (bp.weekly_hours -> :today_f3 ->> \'closes\')
              ))
              AND (NOT :payable_only OR (
                    pb.id IS NOT NULL AND e.publication_state = \'opted_in\'
                    AND EXISTS (SELECT 1 FROM products p WHERE p.enterprise_id = e.id AND p.status = \'active\' AND p.price_minor > 0)
              ))
              AND NOT :inspection_blocked
              AND (NOT :delivers_only OR (
                    pb.id IS NOT NULL AND e.publication_state = \'opted_in\' AND bp.delivers IS TRUE
              ))
              -- Search as I move the map. A published business by its cell
              -- centre, as its pin; an unclaimed one only by its ward, the
              -- finest place it may be named at, so a tight box can never
              -- narrow it further than the ward.
              AND (NOT :has_box OR (
                    (pb.id IS NOT NULL AND e.publication_state = \'opted_in\'
                     AND ST_Intersects(ST_MakeEnvelope(:bw, :bs, :be, :bn, 4326),
                         h3_cell_to_geometry(h3_cell_to_parent(s.h3_index::h3index, '.self::MAP_RESOLUTION.'))))
                 OR ((pb.id IS NULL OR e.publication_state <> \'opted_in\')
                     AND ST_Intersects(ST_MakeEnvelope(:bw2, :bs2, :be2, :bn2, 4326), ward.boundary))
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
            $this->bindings(['sector' => $sector, 'lga' => $lga]) + [
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

            // What the owner states and what buyers have said, for a claimed
            // and published listing only. Distance is to the cell centre the
            // pin sits on, never to the building, and rounded; the cell is the
            // one the pin already shows.
            'establishedOn' => $claimed ? Carbon::parse((string) $row->established_at)->toDateString() : null,
            'cell' => $claimed ? (string) $row->cell : null,
            'distanceKm' => $claimed && $row->distance_m !== null ? round((float) $row->distance_m / 1000, 1) : null,
            'openNow' => $claimed && $row->weekly_hours !== null ? (bool) $row->open_now : null,
            'hours' => $claimed && $row->weekly_hours !== null ? json_decode((string) $row->weekly_hours, true) : null,
            'delivers' => $claimed ? ($row->delivers === null ? null : (bool) $row->delivers) : null,
            'address' => $claimed && $row->street_address !== null ? (string) $row->street_address : null,
            'payable' => $claimed && (bool) $row->payable,
            'rating' => $claimed && $row->rating !== null ? (float) $row->rating : null,
            'reviewCount' => $claimed ? (int) $row->review_count : 0,
            'ordersCompleted' => $claimed ? (int) $row->orders_completed : 0,
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
     * @param  array{term?: string, sector?: string|null, lga?: string|null, ward?: string|null, verifiedOnly?: bool, withPhotos?: bool, withProducts?: bool, openNow?: bool, payable?: bool, inspection?: bool, delivers?: bool, near?: array{0: float, 1: float}|null, box?: array{0: float, 1: float, 2: float, 3: float}|null}  $filters
     * @return array<string, mixed>
     */
    public function mapFor(array $filters): array
    {
        DB::statement('SELECT set_limit(?)', [self::SIMILARITY_FLOOR]);

        $bindings = $this->bindings($filters);

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
            SELECT enterprise_id, trading_name, sector_name, ward, origin, structure_status, established_at, open_now,
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
                    'openNow' => (bool) $r->open_now,
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
