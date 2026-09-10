<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Registry\Actions\ReadDirectorySectors;
use App\Domain\Registry\Actions\SearchDirectory;
use App\Domain\Registry\Actions\WithholdOnRequest;
use App\Domain\Registry\Models\Enterprise;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $page = $search->run(
            term: (string) $request->query('q', ''),
            sector: $this->stringOrNull($request->query('sector')),
            lga: $this->stringOrNull($request->query('lga')),
            page: (int) $request->query('page', 1),
        );

        return Inertia::render('public/Directory', [
            'query' => [
                'q' => (string) $request->query('q', ''),
                'sector' => $this->stringOrNull($request->query('sector')),
                'lga' => $this->stringOrNull($request->query('lga')),
            ],
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
    public function show(Enterprise $enterprise, SearchDirectory $search): Response
    {
        $match = collect($search->run(term: $enterprise->trading_name)['results'])
            ->firstWhere('id', $enterprise->id);

        abort_if($match === null, 404);

        return Inertia::render('public/DirectoryListing', [
            'listing' => $match,
            'similar' => $search->similarTo(
                $enterprise->id,
                is_string($match['sectorCode']) ? $match['sectorCode'] : null,
                is_string($match['lga']) ? $match['lga'] : null,
            ),
        ])->withViewData([
            'robots' => $match['depth'] === 'reduced' ? 'noindex, nofollow' : 'index, follow',
        ]);
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
