<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Commerce\Enums\PayChannel;
use App\Domain\Commerce\Enums\Protection;
use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Actions\SearchDirectory;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A buyer checks out: an order, priced here, waiting for the provider.
 *
 * Nothing the browser sends is a price. It sends product ids and quantities,
 * and every amount is read from the catalogue inside this transaction and
 * copied onto the lines, so a buyer cannot name their own price and a merchant
 * cannot change one under a buyer who already agreed.
 *
 * What can be bought is exactly what the directory shows: the listing resolves
 * through SearchDirectory and the products through its productsFor, so a
 * product on an unpublished or reduced listing cannot be ordered by knowing
 * its id.
 */
final class PlacePurchase
{
    public const MAX_LINES = 30;

    public function __construct(
        private readonly SearchDirectory $directory,
        private readonly ManageInspections $inspections,
    ) {}

    /**
     * @param  array<int, int>  $quantities  Product id to quantity.
     * @param  array{name: string, phone: string, address: string, note?: string|null}  $delivery
     */
    public function __invoke(
        PortalAccount $buyer,
        Enterprise $enterprise,
        array $quantities,
        array $delivery,
        Protection $protection,
        PayChannel $channel,
        ?Carbon $visitAt = null,
        ?string $visitMode = null,
    ): PurchaseOrder {
        if (! $buyer->canSignIn()) {
            throw new RuntimeException('This account cannot place orders.');
        }

        if (! $protection->isOffered()) {
            throw new RuntimeException("{$protection->label()} is not available yet.");
        }

        $quantities = array_filter($quantities, static fn (int $q): bool => $q > 0);

        if ($quantities === []) {
            throw new RuntimeException('Your cart is empty.');
        }

        if (count($quantities) > self::MAX_LINES) {
            throw new RuntimeException('That is more lines than one order can hold. Split it into two.');
        }

        foreach ($quantities as $quantity) {
            if ($quantity > 999) {
                throw new RuntimeException('No line can be more than 999 of anything.');
            }
        }

        $listing = $this->directory->listing($enterprise);

        if ($listing === null || $listing['depth'] === 'reduced') {
            throw new RuntimeException('This business is not taking orders on GeoVerify.');
        }

        $buyable = array_column($this->directory->productsFor($listing), 'id');

        foreach (array_keys($quantities) as $productId) {
            if (! in_array($productId, $buyable, true)) {
                throw new RuntimeException('Something in your cart is no longer listed. Refresh the page and try again.');
            }
        }

        return DB::transaction(function () use ($buyer, $enterprise, $quantities, $delivery, $protection, $channel, $visitAt, $visitMode): PurchaseOrder {
            $control = PartyBusiness::query()
                ->where('enterprise_id', $enterprise->id)
                ->where('status', PartyBusiness::STATUS_ACTIVE)
                ->first();

            if (! $control instanceof PartyBusiness) {
                throw new RuntimeException('This business is not taking orders on GeoVerify.');
            }

            // Buying from yourself would count as an order completed, and that
            // number is on the public profile.
            $selfDealing = $buyer->liveMemberships()->where('party_id', $control->party_id)->exists();

            if ($selfDealing) {
                throw new RuntimeException('You manage this business, so you cannot buy from it.');
            }

            /** @var Collection<int, Product> $products */
            $products = Product::query()
                ->whereIn('id', array_keys($quantities))
                ->where('enterprise_id', $enterprise->id)
                ->where('status', Product::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lines = [];

            foreach ($quantities as $productId => $quantity) {
                $product = $products->get($productId);

                if (! $product instanceof Product || $product->price_minor === null || $product->price_minor <= 0) {
                    throw new RuntimeException('Something in your cart has no price. Ask the business, or remove it.');
                }

                $lines[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'unit' => $product->unit,
                    'unit_price_minor' => $product->price_minor,
                    'quantity' => $quantity,
                    'line_minor' => $product->price_minor * $quantity,
                ];
            }

            $items = array_sum(array_column($lines, 'line_minor'));
            $deliveryFee = (int) config('geoverify.commerce.delivery_fee_minor', 0);
            $serviceFee = $protection->feeMinor() ?? throw new RuntimeException("{$protection->label()} has no price yet.");

            $order = PurchaseOrder::query()->create([
                'reference' => $this->reference(),
                'enterprise_id' => $enterprise->id,
                'seller_party_id' => $control->party_id,
                'buyer_account_id' => $buyer->id,
                'status' => PurchaseStatus::AwaitingPayment,
                'protection' => $protection,
                'channel' => $channel,
                'items_minor' => $items,
                'delivery_minor' => $deliveryFee,
                'service_fee_minor' => $serviceFee,
                'amount_minor' => $items + $deliveryFee + $serviceFee,
                'currency' => 'NGN',
                'delivery_name' => trim($delivery['name']),
                'delivery_phone' => trim($delivery['phone']),
                'delivery_address' => trim($delivery['address']),
                'delivery_note' => isset($delivery['note']) && trim($delivery['note']) !== '' ? trim($delivery['note']) : null,
            ]);

            $order->items()->createMany($lines);

            // The job the buyer paid for, with its arrangement. It waits in
            // the console's queue from the moment the money is held.
            $this->inspections->request($order, $visitAt, $visitMode);

            VerificationEvent::recordForBuyer($order, 'purchase.placed', $buyer, [
                'reference' => $order->reference,
                'amount_minor' => $order->amount_minor,
                'lines' => count($lines),
                'protection' => $protection->value,
                'channel' => $channel->value,
            ]);

            return $order;
        });
    }

    private function reference(): string
    {
        $next = DB::selectOne("SELECT nextval('purchase_order_number_seq') AS n");

        return 'GV-'.(string) $next->n;
    }
}
