<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Commerce\Actions\ManagePurchase;
use App\Domain\Commerce\Actions\PresentPurchase;
use App\Domain\Commerce\Enums\PurchaseStatus;
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
                'dispatch' => $order->status === PurchaseStatus::Held && $membership->role->fulfils(),
            ],
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
