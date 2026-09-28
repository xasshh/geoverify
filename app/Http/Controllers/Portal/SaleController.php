<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Commerce\Actions\ManagePurchase;
use App\Domain\Commerce\Actions\PresentPurchase;
use App\Domain\Commerce\Enums\Protection;
use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\Inspection;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Http\Controllers\Portal\Concerns\ActsForBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The merchant's side of a product order: what to pack, where, and what they will receive. */
final class SaleController
{
    use ActsForBusiness;

    public function show(Request $request, PurchaseOrder $order, PresentPurchase $present): Response
    {
        $membership = $this->membership($request);

        if ($order->seller_party_id !== $membership->party_id) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('portal/Sale', [
            'order' => $present($order),
            'can' => [
                // The same rule ManagePurchase enforces, so the button is not
                // offered where the press would be refused.
                'dispatch' => $order->status === PurchaseStatus::Held && $membership->role->fulfils()
                    && ($order->protection === Protection::None || Inspection::query()
                        ->where('purchase_order_id', $order->id)->where('status', Inspection::APPROVED)->exists()),
            ],
        ]);
    }

    /** Inspections & visits: agents booked to check goods at this party's shops. */
    public function inspections(Request $request): Response
    {
        $membership = $this->membership($request);

        $jobs = Inspection::query()
            ->with(['order:id,reference,enterprise_id,seller_party_id,delivery_name,status', 'order.enterprise:id,trading_name', 'agent:id,name,staff_ref'])
            ->whereHas('order', static fn ($q) => $q->where('seller_party_id', $membership->party_id)
                ->whereNotIn('status', [PurchaseStatus::AwaitingPayment->value, PurchaseStatus::Cancelled->value]))
            ->orderByRaw("CASE status WHEN 'assigned' THEN 0 WHEN 'requested' THEN 1 WHEN 'submitted' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('portal/Inspections', [
            'jobs' => $jobs->map(static fn (Inspection $i): array => [
                'id' => $i->id,
                'orderId' => $i->purchase_order_id,
                'label' => $i->label(),
                'status' => $i->status,
                'orderRef' => $i->order?->reference,
                'business' => $i->order?->enterprise->trading_name,
                'buyer' => $i->order?->delivery_name,
                'requestedFor' => $i->requested_for?->toIso8601String(),
                'agent' => $i->agent === null ? null : ['name' => $i->agent->name, 'ref' => $i->agent->staff_ref],
            ])->all(),
        ]);
    }

    public function dispatch(Request $request, PurchaseOrder $order, ManagePurchase $purchases): RedirectResponse
    {
        $membership = $this->membership($request);

        if ($order->seller_party_id !== $membership->party_id) {
            throw new NotFoundHttpException;
        }

        try {
            $purchases->dispatch($order, $membership);
        } catch (RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('status', 'Marked as dispatched. The buyer has been told it is on the way.');
    }
}
