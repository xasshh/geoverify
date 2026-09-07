<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Enums\OrderUrgency;
use App\Domain\Verification\Models\VerificationEvent;
use App\Domain\Verification\Models\VerificationOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A party buys a rung of the ladder.
 *
 * Places the order and holds nothing. No money has moved, nobody is deployed,
 * and the clock has not started: all of that waits for the provider to tell us
 * the payment cleared, which is the only source this system believes.
 *
 * Everything commercial is stamped here and never joined to again. The price,
 * the currency, the SLA and the zone are copied out of verification_prices, so
 * this order remains an accurate record of what was agreed however the price
 * list changes afterwards.
 */
final class PlaceOrder
{
    public function __construct(
        private readonly ResolveServiceZone $zones,
        private readonly ResolveVerificationPrice $prices,
    ) {}

    public function __invoke(
        Party $party,
        PortalAccount $orderedBy,
        Enterprise $enterprise,
        string $tier,
        OrderUrgency $urgency = OrderUrgency::Standard,
    ): VerificationOrder {
        $this->assertControls($party, $enterprise);

        $enterprise->loadMissing('structure');
        $structure = $enterprise->structure;

        $zone = ($this->zones)($structure);
        $price = ($this->prices)($tier, $urgency, $zone);

        return DB::transaction(function () use (
            $party, $orderedBy, $enterprise, $structure, $tier, $urgency, $zone, $price
        ): VerificationOrder {
            $this->assertNothingLive($enterprise, $tier);

            $order = VerificationOrder::query()->create([
                'reference' => $this->reference(),
                'party_id' => $party->id,
                'enterprise_id' => $enterprise->id,
                // Copied rather than followed. A listing that later moves must
                // not silently redirect a paid visit to a different building.
                'structure_id' => $structure->id,
                'ordered_by' => $orderedBy->id,
                'tier' => $tier,
                'urgency' => $urgency,
                'zone' => $zone,
                'price_id' => $price->id,
                'amount_minor' => $price->amount_minor,
                'currency' => $price->currency,
                'sla_working_days' => $price->sla_working_days,
                'status' => OrderStatus::AwaitingPayment,
            ]);

            // Placed by the party, in the party's name. The audit log is the
            // product, and an order whose origin is only inferable from a
            // foreign key is one nobody can answer a dispute with.
            VerificationEvent::recordForParty($order, 'order.placed', $party, [
                'reference' => $order->reference,
                'tier' => $tier,
                'urgency' => $urgency->value,
                'zone' => $zone->value,
                'amount_minor' => $order->amount_minor,
                'currency' => $order->currency,
                'ordered_by' => $orderedBy->id,
            ]);

            return $order;
        });
    }

    /**
     * Only the party that manages the listing may buy against it.
     *
     * Checked against the live control row rather than the claim that produced
     * it, so a listing transferred by a dispute stops being purchasable by its
     * former holder the moment it is transferred.
     */
    private function assertControls(Party $party, Enterprise $enterprise): void
    {
        $controls = PartyBusiness::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('party_id', $party->id)
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->exists();

        if (! $controls) {
            throw new RuntimeException('You do not manage this business.');
        }
    }

    /**
     * One live order per listing per tier.
     *
     * The unique index is what actually decides; this is here to say why in
     * words a customer can read, rather than surfacing a constraint violation.
     */
    private function assertNothingLive(Enterprise $enterprise, string $tier): void
    {
        $live = VerificationOrder::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('tier', $tier)
            ->whereIn('status', array_map(
                static fn (OrderStatus $s): string => $s->value,
                array_filter(
                    OrderStatus::cases(),
                    static fn (OrderStatus $s): bool => ! $s->isSettled(),
                ),
            ))
            ->lockForUpdate()
            ->exists();

        if ($live) {
            throw new RuntimeException(
                'You already have that verification in progress. Paying twice would not make an officer arrive sooner.',
            );
        }
    }

    /**
     * GV-2026-000123. Sequential within the year, and readable down a phone.
     *
     * Counted inside the transaction that creates the row, so two orders placed
     * in the same second cannot take the same number.
     */
    private function reference(): string
    {
        $year = now(config('app.timezone'))->format('Y');
        $prefix = "GV-{$year}-";

        $highest = DB::scalar(
            "select max(substring(reference from '[0-9]+$')::int) from verification_orders where reference like ?",
            [$prefix.'%'],
        );

        return $prefix.str_pad((string) (((int) $highest) + 1), 6, '0', STR_PAD_LEFT);
    }
}
