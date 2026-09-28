<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\Inspection;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Domain\Commerce\Models\PurchaseOrderItem;
use App\Domain\Media\Models\Media;

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
            'inspection' => $this->inspection($order),
        ];
    }

    /**
     * The agent's report, for the two parties to the order.
     *
     * The only place an officer's photograph is shown outside the staff side,
     * and only these: kind `inspection`, attached to this order's inspection,
     * through links that expire in minutes. Where the agent stood is reduced
     * to whether it matched the premises; neither point is disclosed.
     *
     * @return array<string, mixed>|null
     */
    private function inspection(PurchaseOrder $order): ?array
    {
        /** @var Inspection|null $i */
        $i = Inspection::query()->with(['agent:id,name,staff_ref', 'photos'])->where('purchase_order_id', $order->id)->first();

        if ($i === null) {
            return null;
        }

        return [
            'id' => $i->id,
            'kind' => $i->kind,
            'label' => $i->label(),
            'status' => $i->status,
            'requestedFor' => $i->requested_for?->toIso8601String(),
            'visitMode' => $i->visit_mode,
            'agent' => $i->agent === null ? null : ['name' => $i->agent->name, 'ref' => $i->agent->staff_ref],
            'arrivedAt' => $i->arrived_at?->toIso8601String(),
            'matchedPremises' => $i->arrival_distance_m === null ? null : $i->arrival_distance_m <= Inspection::ARRIVAL_TOLERANCE_M,
            'onSiteMinutes' => $i->arrived_at === null || $i->submitted_at === null ? null : (int) $i->arrived_at->diffInMinutes($i->submitted_at),
            'checklist' => $i->checklist ?? [],
            'notes' => $i->notes,
            'submittedAt' => $i->submitted_at?->toIso8601String(),
            'decidedAt' => $i->decided_at?->toIso8601String(),
            'decisionNote' => $i->decision_note,
            'photos' => $i->status === Inspection::REQUESTED || $i->status === Inspection::ASSIGNED
                ? []
                : $i->photos->map(static fn (Media $m): array => ['url' => $m->temporaryUrl(15)])->values()->all(),
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
        ];

        $inspection = Inspection::query()->with('agent:id,staff_ref')->withCount('photos')->where('purchase_order_id', $order->id)->first();

        if ($inspection !== null) {
            $name = $inspection->kind === Inspection::KIND_SITE_VISIT ? 'Visit' : 'Inspection';
            $steps[] = ['label' => "{$name} assigned", 'at' => $inspection->assigned_at?->toIso8601String(), 'detail' => $inspection->agent?->staff_ref === null ? null : 'agent '.$inspection->agent->staff_ref];
            $steps[] = ['label' => "{$name} report uploaded", 'at' => $inspection->submitted_at?->toIso8601String(), 'detail' => $inspection->submitted_at === null ? null : trans_choice(':count photo|:count photos', (int) $inspection->getAttribute('photos_count'))];
            $steps[] = ['label' => 'Buyer approval', 'at' => $inspection->decided_at?->toIso8601String(), 'detail' => match ($inspection->status) {
                Inspection::APPROVED => 'Approved',
                Inspection::REJECTED => 'Not accepted',
                Inspection::SUBMITTED => 'Waiting for the buyer',
                default => null,
            }];
        }

        $steps[] = ['label' => 'Dispatched', 'at' => $order->dispatched_at?->toIso8601String(), 'detail' => null];

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
