<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enumerate;

use App\Domain\Enumerate\Actions\ReadEnumeratePrices;
use App\Domain\Enumerate\Registry\RegistryLookup;
use App\Domain\Enumerate\Registry\RegistryMatch;
use App\Domain\Enumerate\Registry\RegistryUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Enumerate's public front: the landing page and its free search.
 *
 * The search asks the same register a signed-in requester's search box does,
 * and every uncached question is a paid lookup at the provider. So it is
 * answered from a week-long cache where it can be, limited per address by the
 * minute and by the day, and it returns only what the register says publicly:
 * a name, a number, a type, a status. Directors, TIN and everything a check
 * produces stay behind sign-in and payment.
 */
final class LandingController
{
    /** Searches an address may run in a day. A person comparing suppliers runs a handful. */
    private const DAILY_PER_ADDRESS = 40;

    public function show(ReadEnumeratePrices $prices): Response
    {
        return Inertia::render('enumerate/Landing', [
            'prices' => $prices->list(),
            // States where there is ground under contract, so the field
            // network map lights up what is real rather than a claim.
            'coveredStates' => DB::table('coverage_areas')
                ->whereNotNull('state_code')
                ->distinct()
                ->orderBy('state_code')
                ->pluck('state_code')
                ->all(),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $input = $request->validate([
            'by' => ['required', 'in:name,rc'],
            'q' => ['required', 'string', 'min:3', 'max:120'],
        ]);

        $term = mb_strtolower(trim(preg_replace('/\s+/', ' ', $input['q']) ?? ''));
        $key = 'enumerate:public-search:'.$input['by'].':'.sha1($term);

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return new JsonResponse(['matches' => $cached, 'cached' => true]);
        }

        $daily = 'enumerate-public-search:day:'.$request->ip();

        if (RateLimiter::tooManyAttempts($daily, self::DAILY_PER_ADDRESS)) {
            return new JsonResponse([
                'message' => 'That is the day\'s free searches from this connection. Sign in to keep searching.',
            ], 429);
        }

        RateLimiter::hit($daily, 86_400);

        try {
            // Resolved here, not injected: a server with no registry keys
            // refuses to build the lookup at all, and that refusal has to land
            // in the catch below rather than escape as a bare 500.
            $registry = app(RegistryLookup::class);

            $matches = array_map(
                static fn (RegistryMatch $m): array => $m->toArray(),
                array_slice($registry->search($input['by'], $input['q']), 0, 8),
            );
        } catch (RegistryUnavailable) {
            return new JsonResponse(['message' => 'The register is not answering just now. Try again in a minute.'], 503);
        } catch (Throwable) {
            // A server with no registry keys yet, or any other fault: the
            // visitor is told plainly rather than shown a stack.
            return new JsonResponse(['message' => 'Search is not available just now. Sign in to run a check.'], 503);
        }

        Cache::put($key, $matches, now()->addDays(7));

        return new JsonResponse(['matches' => $matches, 'cached' => false]);
    }
}
