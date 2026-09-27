<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Domain\Commerce\Models\PurchaseOrderItem;

/**
 * One product order, as its buyer and its merchant both see it.
 *
 * One projection for both sides so they cannot describe the same order
 * differently. What differs is only what each may do, which the controller
 * adds. The delivery address is in it because the merchant needs it to deliver
 * and the buyer gave it for that; nothing else about either party is.
 */
final class PresentPurchase
{
    /** @return array<string, mixed> */
    public function __invoke(PurchaseOrder $order): array
    {
        $order->loadMissing(['items', 'enterprise.structure.ward', 'enterprise.structure.lga', 'buyer']);

        $structure = $order->enterprise?->structure;
        $place = implode(', ', array_filter([$structure?->ward?->name, $structure?->lga?->name]));
        $commission = $order->commission_minor ?? $order->commissionDue();

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'status' => $order->status->value,
            'statusLabel' => $order->status->label(),
            'business' => [
                'id' => $order->enterprise_id,
                'name' => $order->enterprise?->trading_name,
                'place' => $place === '' ? null : $place,
            ],
            'buyerName' => $order->delivery_name,
            'items' => $order->items->map(static fn (PurchaseOrderItem $i): array => [
                'name' => $i->name,
                'unit' => $i->unit,
                'quantity' => $i->quantity,
                'unitNaira' => intdiv($i->unit_price_minor, 100),
                'lineNaira' => intdiv($i->line_minor, 100),
            ])->values()->all(),
            'protection' => $order->protection->value,
            'protectionLabel' => $order->protection->label(),
            'channel' => $order->channel->label(),
            'money' => [
                'itemsNaira' => intdiv($order->items_minor, 100),
                'deliveryNaira' => intdiv($order->delivery_minor, 100),
                'serviceFeeNaira' => intdiv($order->service_fee_minor, 100),
                'totalNaira' => intdiv($order->amount_minor, 100),
                'commissionNaira' => intdiv($commission, 100),
                'commissionRate' => ((int) config('geoverify.commerce.commission_basis_points', 0)) / 100,
                'netNaira' => intdiv($order->netPayoutMinor($commission), 100),
            ],
            'delivery' => [
                'name' => $order->delivery_name,
                'phone' => $order->delivery_phone,
                'address' => $order->delivery_address,
                'note' => $order->delivery_note,
            ],
            'dispute' => $order->dispute_reason,
            'placedAt' => $order->created_at?->toIso8601String(),
            'timeline' => $this->timeline($order),
            'releaseAfterDays' => (int) config('geoverify.commerce.release_after_days', 7),
        ];
    }

    /**
     * The mockup's timeline, from the order's own dates. A step with no date
     * has not happened, and is drawn that way.
     *
     * @return list<array{label: string, at: string|null, detail: string|null}>
     */
    private function timeline(PurchaseOrder $order): array
    {
        $steps = [
            ['label' => 'Paid and held', 'at' => $order->paid_at?->toIso8601String(), 'detail' => $order->paid_at === null ? null : $order->channel->label()],
            ['label' => 'Dispatched', 'at' => $order->dispatched_at?->toIso8601String(), 'detail' => null],
        ];

        if ($order->status === PurchaseStatus::Disputed || $order->disputed_at !== null) {
            $steps[] = ['label' => 'Buyer raised issue', 'at' => $order->disputed_at?->toIso8601String(), 'detail' => null];
        }

        if ($order->status === PurchaseStatus::Refunded) {
            $steps[] = ['label' => 'Refunded to buyer', 'at' => $order->refunded_at?->toIso8601String(), 'detail' => null];
        } else {
            $steps[] = [
                'label' => 'Delivered, funds released',
                'at' => $order->released_at?->toIso8601String(),
                'detail' => match ($order->released_by) {
                    PurchaseOrder::RELEASED_BY_BUYER => 'Buyer confirmed',
                    PurchaseOrder::RELEASED_BY_WINDOW => 'No issue raised in time',
                    PurchaseOrder::RELEASED_BY_RULING => 'On review',
                    default => null,
                },
            ];
        }

        return $steps;
    }
}
