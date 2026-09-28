<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Commerce\Enums\Protection;
use App\Domain\Registry\Actions\ReadDirectorySectors;
use App\Domain\Registry\Actions\ReadRoadsInBox;
use App\Domain\Registry\Actions\SearchDirectory;
use App\Domain\Registry\Actions\WithholdOnRequest;
use App\Domain\Registry\Models\Enterprise;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The directory anybody can read.
 *
 * Open by design, which is the first surface in this system that is. What that
 * costs is that every projection here is a disclosure decision, so all of them
 * live in SearchDirectory rather than in this controller: a controller that can
 * widen what it renders is a controller that eventually does.
 *
 * Indexing is the one step that cannot be taken back. A page for a business
 * that never asked to be here renders `noindex` however complete it looks;
 * becoming findable is what a business gets for claiming its listing and saying
 * yes, and nothing else earns it.
 */
final class DirectoryController extends Controller
{
    public function __construct(private readonly ReadDirectorySectors $sectors) {}

    public function index(Request $request, SearchDirectory $search): Response
    {
        $filters = [
            'term' => (string) $request->query('q', ''),
            'sector' => $this->stringOrNull($request->query('sector')),
            'lga' => $this->stringOrNull($request->query('lga')),
            'ward' => $this->stringOrNull($request->query('where')),
            'verifiedOnly' => $request->boolean('verified'),
            'withPhotos' => $request->boolean('photos'),
            'withProducts' => $request->boolean('products'),
            'openNow' => $request->boolean('open'),
            'payable' => $request->boolean('pays'),
            'inspection' => $request->boolean('inspection'),
            'delivers' => $request->boolean('delivers'),
            // The reader's own position, for "nearest": used for this search
            // and not kept anywhere.
            'near' => $this->pair($request->query('near')),
            'box' => $this->box($request->query('box')),
        ];
        $sort = $this->stringOrNull($request->query('sort')) ?? 'relevance';

        $page = $search->run(
            term: $filters['term'],
            sector: $filters['sector'],
            lga: $filters['lga'],
            page: (int) $request->query('page', 1),
            ward: $filters['ward'],
            verifiedOnly: $filters['verifiedOnly'],
            withPhotos: $filters['withPhotos'],
            withProducts: $filters['withProducts'],
            sort: $sort,
            openNow: $filters['openNow'],
            payable: $filters['payable'],
            inspection: $filters['inspection'],
            delivers: $filters['delivers'],
            near: $filters['near'],
            box: $filters['box'],
        );

        return Inertia::render('public/Directory', [
            'query' => [
                'q' => $filters['term'],
                'sector' => $filters['sector'],
                'lga' => $filters['lga'],
                'where' => $filters['ward'],
                'verified' => $filters['verifiedOnly'],
                'photos' => $filters['withPhotos'],
                'products' => $filters['withProducts'],
                'sort' => $sort,
                'open' => $filters['openNow'],
                'pays' => $filters['payable'],
                'inspection' => $filters['inspection'],
                'delivers' => $filters['delivers'],
                'near' => $filters['near'] === null ? null : implode(',', array_reverse($filters['near'])),
                'box' => $filters['box'] === null ? null : implode(',', $filters['box']),
            ],
            // The inspection chip is offered only once inspections have a price.
            'inspectionOffered' => Protection::Inspection->isOffered(),
            'saved' => $this->savedIds($request),
            'map' => $search->mapFor($filters),
            'places' => $search->places(),
            'results' => $page['results'],
            'total' => $page['total'],
            'pageNumber' => $page['page'],
            'pages' => $page['pages'],
            'sectors' => array_slice($this->sectors->all(), 0, 12),
            'lgas' => $this->lgas(),

            // Only when a search found nothing. A page with results does not
            // need to guess at what somebody meant, and guessing over a good
            // answer is how a directory starts arguing with its reader.
            'meaning' => $page['results'] === [] && $request->query('q') !== null
                ? $search->sectorsMeaning((string) $request->query('q'))
                : [],
        ])->withViewData([
            // The index itself is indexable: it lists nothing a listing page
            // does not, and a directory nobody can find is not a directory.
            'robots' => 'index, follow',
        ]);
    }

    /**
     * One listing, at whatever depth it has earned.
     *
     * Resolved through the same search rather than by loading the model and
     * reading fields off it. One projection means a listing page can never show
     * something the list would have withheld, which is the failure this is
     * arranged to make impossible rather than merely unlikely.
     */
    public function show(Request $request, Enterprise $enterprise, SearchDirectory $search): Response
    {
        $match = $search->listing($enterprise);

        abort_if($match === null, 404);

        return Inertia::render('public/DirectoryListing', [
            'listing' => $match,
            'products' => $search->productsFor($match),
            'reviews' => $match['depth'] === 'reduced' ? [] : $this->reviewsFor($enterprise->id),
            'saved' => in_array($enterprise->id, $this->savedIds($request), true),
            // What a buyer can add at checkout, and for how much, so the page
            // never promises a service that has no price yet.
            'services' => array_map(static fn (Protection $p): array => [
                'value' => $p->value,
                'feeNaira' => $p->feeMinor() === null ? null : intdiv($p->feeMinor(), 100),
            ], [Protection::Inspection, Protection::SiteVisit]),
            'similar' => $search->similarTo(
                $enterprise->id,
                is_string($match['sectorCode']) ? $match['sectorCode'] : null,
                is_string($match['lga']) ? $match['lga'] : null,
            ),
        ])->withViewData([
            'robots' => $match['depth'] === 'reduced' ? 'noindex, nofollow' : 'index, follow',
        ]);
    }

    /**
     * How verification works: the tiers, how long a check stays current, and
     * what the directory will and will not show at each depth.
     *
     * The freshness thresholds are read from the same configuration the
     * listing pages and the public certificate check use, so this page cannot
     * describe a rule the rest of the directory does not follow.
     */
    public function howItWorks(): Response
    {
        return Inertia::render('public/HowItWorks', [
            'currentMonths' => (int) config('geoverify.tier_freshness.current_months'),
            'staleMonths' => (int) config('geoverify.tier_freshness.stale_months'),
        ])->withViewData(['robots' => 'index, follow']);
    }

    /**
     * Published reviews, newest first. A reviewer is shown by first name only:
     * a review is public and the rest of their name is not ours to publish.
     *
     * @return list<array<string, mixed>>
     */
    private function reviewsFor(int $enterpriseId): array
    {
        return array_map(static fn (object $r): array => [
            'id' => (int) $r->id,
            'rating' => (int) $r->rating,
            'body' => $r->body,
            'by' => explode(' ', trim((string) $r->name))[0] ?: 'A buyer',
            'on' => Carbon::parse((string) $r->created_at)->toDateString(),
        ], DB::select(
            "SELECT r.id, r.rating, r.body, r.created_at, pa.name
               FROM reviews r JOIN portal_accounts pa ON pa.id = r.buyer_account_id
              WHERE r.enterprise_id = ? AND r.status = 'published'
              ORDER BY r.created_at DESC LIMIT 50",
            [$enterpriseId],
        ));
    }

    /** Every sector the directory holds something in. */
    public function sectors(): Response
    {
        return Inertia::render('public/Sectors', [
            'sectors' => $this->sectors->all(),
            'unclassified' => $this->sectors->unclassified(),
        ])->withViewData(['robots' => 'index, follow']);
    }

    /**
     * One sector: what is in it, and where.
     *
     * The businesses come from the same search the index uses, so a sector page
     * is a filtered directory rather than a second way of reading the register.
     */
    public function sector(string $code, SearchDirectory $search): Response
    {
        $sector = $this->sectors->one($code);

        abort_if($sector === null, 404);

        $page = $search->run(sector: $code);

        return Inertia::render('public/Sector', [
            'sector' => $sector,
            'results' => $page['results'],
            'total' => $page['total'],
        ])->withViewData(['robots' => 'index, follow']);
    }

    /**
     * Streets under the directory map, for the box the reader is looking at.
     *
     * Public OpenStreetMap geometry and nothing about a business, which is why
     * it answers without a session. A box that is not four finite numbers gets
     * an empty collection rather than an error.
     */
    public function roads(Request $request, ReadRoadsInBox $roads): JsonResponse
    {
        $box = array_map(
            static fn (string $key): mixed => filter_var($request->query($key), FILTER_VALIDATE_FLOAT),
            ['w', 's', 'e', 'n'],
        );

        if (in_array(false, $box, true)) {
            return new JsonResponse(['type' => 'FeatureCollection', 'features' => []]);
        }

        /** @var array{float, float, float, float} $box */
        return (new JsonResponse($roads(...$box)))
            ->setPublic()
            ->setMaxAge(3600);
    }

    /** Asking for a listing to come down. No account, on purpose. */
    public function remove(
        Request $request,
        Enterprise $enterprise,
        WithholdOnRequest $withhold,
    ): RedirectResponse {
        $reason = $request->string('reason')->limit(280)->toString();

        $removed = $withhold($enterprise, $reason === '' ? null : $reason);

        return back()->with(
            'status',
            $removed
                ? 'This listing has been taken out of the directory.'
                : 'The owner of this listing published it themselves, so we have passed your request to a reviewer rather than removing it.',
        );
    }

    /**
     * "lat,lng" as the page sends it, into [lng, lat], or null.
     *
     * @return array{0: float, 1: float}|null
     */
    private function pair(mixed $value): ?array
    {
        if (! is_string($value) || preg_match('/^(-?\d{1,2}(\.\d+)?),(-?\d{1,3}(\.\d+)?)$/', $value, $m) !== 1) {
            return null;
        }

        return [(float) $m[3], (float) $m[1]];
    }

    /**
     * "west,south,east,north", bounded to a sensible span, or null.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function box(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $parts = array_map(static fn (string $p): mixed => filter_var($p, FILTER_VALIDATE_FLOAT), explode(',', $value));

        if (count($parts) !== 4 || in_array(false, $parts, true)) {
            return null;
        }

        /** @var array{0: float, 1: float, 2: float, 3: float} $parts */
        [$w, $south, $e, $n] = $parts;

        return $e > $w && $n > $south && ($e - $w) < 5 && ($n - $south) < 5 ? [$w, $south, $e, $n] : null;
    }

    /** @return list<int> */
    private function savedIds(Request $request): array
    {
        $account = $request->user('portal');

        if ($account === null) {
            return [];
        }

        return DB::table('saved_listings')->where('portal_account_id', $account->getAuthIdentifier())->whereNull('removed_at')
            ->pluck('enterprise_id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private function lgas(): array
    {
        return array_map(static fn (object $row): string => (string) $row->name, DB::select(<<<'SQL'
            SELECT DISTINCT lga.name AS name
              FROM enterprises e
              JOIN structures s ON s.id = e.structure_id
              JOIN admin_boundaries lga ON lga.id = s.lga_id
             WHERE s.status <> 'rejected' AND e.publication_state <> 'withheld'
             ORDER BY lga.name ASC
             LIMIT 40
        SQL));
    }
}
