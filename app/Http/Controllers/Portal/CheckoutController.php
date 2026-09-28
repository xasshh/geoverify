<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Commerce\Actions\InitialisePurchasePayment;
use App\Domain\Commerce\Actions\PlacePurchase;
use App\Domain\Commerce\Enums\PayChannel;
use App\Domain\Commerce\Enums\Protection;
use App\Domain\Registry\Actions\SearchDirectory;
use App\Domain\Registry\Models\Enterprise;
use App\Http\Controllers\Portal\Concerns\ActsForBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Cart, delivery, protection and pay, for one business.
 *
 * The cart itself lives in the buyer's browser until this page, because a
 * cart is a note to self and needs no account. What reaches the server is ids
 * and quantities; PlacePurchase prices them. The page is handed the business's
 * buyable products so it can show the lines, and nothing it sends back is a
 * price.
 */
final class CheckoutController
{
    use ActsForBusiness;

    public function show(Enterprise $enterprise, SearchDirectory $directory): Response
    {
        $listing = $directory->listing($enterprise);

        abort_if($listing === null || $listing['depth'] === 'reduced', 404);

        return Inertia::render('portal/Checkout', [
            'business' => [
                'id' => $enterprise->id,
                'name' => $listing['tradingName'],
                'place' => implode(', ', array_filter([$listing['ward'], $listing['lga']])),
                'verified' => (bool) $listing['verified'],
            ],
            'products' => array_values(array_filter(
                $directory->productsFor($listing),
                static fn (array $p): bool => $p['priceNaira'] !== null && $p['priceNaira'] > 0,
            )),
            'deliveryNaira' => intdiv((int) config('geoverify.commerce.delivery_fee_minor', 0), 100),
            'protections' => array_map(static fn (Protection $p): array => [
                'value' => $p->value,
                'label' => $p->label(),
                'feeNaira' => $p->feeMinor() === null ? null : intdiv($p->feeMinor(), 100),
                'offered' => $p->isOffered(),
            ], Protection::cases()),
            'channels' => array_map(static fn (PayChannel $c): array => ['value' => $c->value, 'label' => $c->label()], PayChannel::cases()),
        ]);
    }

    public function store(
        Request $request,
        Enterprise $enterprise,
        PlacePurchase $place,
        InitialisePurchasePayment $payments,
    ): RedirectResponse|HttpResponse {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.PlacePurchase::MAX_LINES],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'delivery.name' => ['required', 'string', 'max:120'],
            'delivery.phone' => ['required', 'string', 'max:24'],
            'delivery.address' => ['required', 'string', 'max:240'],
            'delivery.note' => ['nullable', 'string', 'max:280'],
            'protection' => ['required', Rule::enum(Protection::class)],
            'channel' => ['required', Rule::enum(PayChannel::class)],
            'visit_at' => ['nullable', 'required_if:protection,site_visit', 'date', 'after:now'],
            'visit_mode' => ['nullable', 'required_if:protection,site_visit', Rule::in(['with_me', 'for_me'])],
        ]);

        $quantities = [];

        foreach ($validated['items'] as $line) {
            $quantities[(int) $line['id']] = ($quantities[(int) $line['id']] ?? 0) + (int) $line['quantity'];
        }

        $account = $this->account($request);

        try {
            $order = $place(
                $account,
                $enterprise,
                $quantities,
                $validated['delivery'],
                Protection::from($validated['protection']),
                PayChannel::from($validated['channel']),
                isset($validated['visit_at']) ? Carbon::parse($validated['visit_at'], config('app.timezone')) : null,
                $validated['visit_mode'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['checkout' => $e->getMessage()]);
        }

        try {
            $url = $payments($order, $this->receiptEmail($account->email, $order->reference), route('portal.purchases.return', $order));
        } catch (RuntimeException $e) {
            // The order exists and can be paid from its own page. Sending the
            // buyer there is better than losing what they just filled in.
            return redirect()->route('portal.purchases.show', $order)->withErrors(['payment' => $e->getMessage()]);
        }

        return Inertia::location($url);
    }

    private function receiptEmail(?string $email, string $reference): string
    {
        return strtolower($email ?? "{$reference}@orders.geoverify.ng");
    }
}
