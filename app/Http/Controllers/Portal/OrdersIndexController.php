<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Commerce\Enums\Protection;
use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Domain\Commerce\Models\PurchaseOrderItem;
use App\Domain\Verification\Models\VerificationOrder;
use App\Http\Controllers\Portal\Concerns\ActsForBusiness;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Orders: every verification on the businesses this party controls, its own
 * and those an investor commissioned. Goods orders join this list with the
 * checkout (M2).
 */
final class OrdersIndexController
{
    use ActsForBusiness;

    public function __invoke(Request $request): Response
    {
        $membership = $this->membership($request);

        $enterpriseIds = PartyBusiness::query()
            ->where('party_id', $membership->party_id)
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->pluck('enterprise_id');

        $orders = VerificationOrder::query()
            ->with('enterprise:id,trading_name')
            ->whereIn('enterprise_id', $enterpriseIds)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $sales = PurchaseOrder::query()
            ->with('items:id,purchase_order_id,name,quantity')
            ->where('seller_party_id', $membership->party_id)
            // An order nobody has paid for is not the merchant's business yet.
            ->whereNotIn('status', [PurchaseStatus::AwaitingPayment->value, PurchaseStatus::Cancelled->value])
            ->orderByDesc('paid_at')
            ->limit(200)
            ->get();

        return Inertia::render('portal/Orders', [
            'sales' => $sales->map(static fn (PurchaseOrder $o): array => [
                'id' => $o->id,
                'reference' => $o->reference,
                'buyer' => $o->delivery_name,
                'items' => $o->items->map(static fn (PurchaseOrderItem $i): string => "{$i->name} ×{$i->quantity}")->implode(', '),
                'totalNaira' => intdiv($o->amount_minor, 100),
                'service' => $o->protection === Protection::None ? null : $o->protection->label(),
                'status' => $o->status->value,
                'statusLabel' => $o->status->label(),
                'paidAt' => $o->paid_at?->toIso8601String(),
            ])->all(),
            'orders' => $orders->map(static fn (VerificationOrder $o): array => [
                'id' => $o->id,
                'reference' => $o->reference,
                'business' => $o->enterprise?->trading_name,
                'tier' => str_replace('_', ' ', $o->tier),
                'feeNaira' => (int) round($o->amount_minor / 100),
                'status' => $o->status->value,
                'statusLabel' => $o->status->label(),
                'orderedAt' => $o->created_at?->toIso8601String(),
                'byInvestor' => $o->isCommissionedByInvestor(),
                'open' => $o->party_id === $membership->party_id,
            ])->all(),
        ]);
    }
}
