<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enumerate;

use App\Domain\Enumerate\Actions\EnumerateContext;
use App\Domain\Enumerate\Actions\PlaceEnumerateRequest;
use App\Domain\Enumerate\Actions\PresentEnumerateRequest;
use App\Domain\Enumerate\Actions\ReadEnumeratePrices;
use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Registry\RegistryLookup;
use App\Domain\Enumerate\Registry\RegistryMatch;
use App\Domain\Enumerate\Registry\RegistryUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Finding a business, paying to have it checked, and following the check.
 *
 * A request is shown to the wallet that paid for it and is a 404 to everybody
 * else, so a reference read off somebody's screen opens nothing.
 */
final class RequestController
{
    public function __construct(
        private readonly EnumerateController $enumerate,
        private readonly EnumerateContext $context,
        private readonly PresentEnumerateRequest $present,
    ) {}

    /** The search box's candidates, from the register itself. */
    public function lookup(Request $request, RegistryLookup $registry): JsonResponse
    {
        $input = $request->validate([
            'by' => ['required', 'in:name,rc'],
            'q' => ['required', 'string', 'min:3', 'max:120'],
        ]);

        try {
            $matches = $registry->search($input['by'], $input['q']);
        } catch (RegistryUnavailable $e) {
            return new JsonResponse(['message' => $e->forPeople() ?? 'The register is not answering just now. Try again in a minute.'], 503);
        }

        return new JsonResponse([
            'matches' => array_map(static fn (RegistryMatch $m): array => $m->toArray(), $matches),
        ]);
    }

    public function create(Request $request, ReadEnumeratePrices $prices): Response
    {
        $account = EnumerateController::account($request);

        return Inertia::render('enumerate/New', [
            'frame' => $this->enumerate->frame($request, $account),
            'prices' => $prices->list(),
            'start' => [
                'by' => 'rc',
                'q' => is_string($request->query('q')) ? mb_substr($request->query('q'), 0, 120) : '',
                'tier' => in_array((int) $request->query('tier'), [1, 2, 3], true) ? (int) $request->query('tier') : 1,
            ],
        ]);
    }

    public function store(Request $request, PlaceEnumerateRequest $place): RedirectResponse
    {
        $account = EnumerateController::account($request);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'rc_number' => ['required', 'string', 'max:32'],
            'company_type' => ['required', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:300'],
            'tier' => ['required', 'integer', 'in:1,2,3'],
            'days' => ['nullable', 'integer', 'in:7,14,30'],
        ]);

        $member = $this->context->member($request, $account);

        if ($member !== null && ! $member->may('request')) {
            return back()->withErrors(['tier' => 'Your seat can view this organisation’s checks but not buy them.']);
        }

        try {
            $placed = $place(
                $account,
                [
                    'name' => $input['name'],
                    'rcNumber' => $input['rc_number'],
                    'companyType' => $input['company_type'],
                    'address' => $input['address'] ?? null,
                ],
                Tier::from((int) $input['tier']),
                isset($input['days']) ? (int) $input['days'] : null,
                $this->context->wallet($request, $account),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['tier' => $e->getMessage()]);
        }

        return redirect()->route('enumerate.requests.show', $placed->reference)
            ->with('status', 'Paid. We are checking the registers now.');
    }

    public function index(Request $request): Response
    {
        $account = EnumerateController::account($request);
        $wallet = $this->context->wallet($request, $account);

        return Inertia::render('enumerate/Requests', [
            'frame' => $this->enumerate->frame($request, $account),
            'requests' => EnumerateRequest::query()
                ->where('wallet_id', $wallet->id)
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->map(fn (EnumerateRequest $r): array => $this->present->row($r))
                ->values()
                ->all(),
        ]);
    }

    public function show(Request $request, string $reference): Response
    {
        $account = EnumerateController::account($request);
        $wallet = $this->context->wallet($request, $account);

        $found = EnumerateRequest::query()
            ->where('reference', $reference)
            ->where('wallet_id', $wallet->id)
            ->with('requester')
            ->first() ?? throw new NotFoundHttpException;

        return Inertia::render('enumerate/Request', [
            'frame' => $this->enumerate->frame($request, $account),
            'request' => $this->present->page($found),
        ]);
    }
}
