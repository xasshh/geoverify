<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Commerce\Actions\InitialisePurchasePayment;
use App\Domain\Commerce\Actions\ManageInspections;
use App\Domain\Commerce\Actions\ManagePurchase;
use App\Domain\Commerce\Actions\PresentPurchase;
use App\Domain\Commerce\Enums\Protection;
use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\Inspection;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Domain\Commerce\Models\Review;
use App\Domain\Party\Models\PortalAccount;
use App\Http\Controllers\Portal\Concerns\ActsForBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * My orders: the buyer's side of a product order.
 *
 * Answers only for the account that placed the order, and a stranger's order
 * is a 404 rather than a 403, so an order reference cannot be probed for.
 */
final class PurchaseController
{
    use ActsForBusiness;

    public function __construct(private readonly ManagePurchase $purchases) {}

    public function index(Request $request): Response
    {
        $orders = PurchaseOrder::query()
            ->with('enterprise:id,trading_name')
            ->withCount('items')
            ->where('buyer_account_id', $this->account($request)->id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return Inertia::render('portal/Purchases', [
            'orders' => $orders->map(static fn (PurchaseOrder $o): array => [
                'id' => $o->id,
                'reference' => $o->reference,
                'business' => $o->enterprise?->trading_name,
                'items' => (int) $o->getAttribute('items_count'),
                'totalNaira' => intdiv($o->amount_minor, 100),
                'status' => $o->status->value,
                'statusLabel' => $o->status->label(),
                'placedAt' => $o->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function show(Request $request, PurchaseOrder $order, PresentPurchase $present): Response
    {
        $this->own($request, $order);

        $review = Review::query()->where('purchase_order_id', $order->id)->first();

        return Inertia::render('portal/Purchase', [
            'order' => $present($order),
            'review' => $review === null ? null : ['rating' => $review->rating, 'body' => $review->body, 'status' => $review->status],
            'can' => [
                'pay' => $order->status === PurchaseStatus::AwaitingPayment,
                'cancel' => $order->status === PurchaseStatus::AwaitingPayment,
                // On an inspected order the question before dispatch is the
                // report, not receipt: nothing has been sent to receive.
                'confirm' => $order->status->allowsMoveTo(PurchaseStatus::Released)
                    && ($order->protection === Protection::None || $order->status !== PurchaseStatus::Held),
                'raiseIssue' => $order->status->allowsMoveTo(PurchaseStatus::Disputed),
                'review' => $order->status === PurchaseStatus::Released && $review === null,
            ],
        ]);
    }

    public function pay(Request $request, PurchaseOrder $order, InitialisePurchasePayment $payments): RedirectResponse|HttpResponse
    {
        $account = $this->own($request, $order);

        try {
            $url = $payments($order, strtolower($account->email ?? "{$order->reference}@orders.geoverify.ng"), route('portal.purchases.return', $order));
        } catch (RuntimeException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return Inertia::location($url);
    }

    /** Back from the provider. Changes nothing: the webhook decides. */
    public function return(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $this->own($request, $order);

        return redirect()
            ->route('portal.purchases.show', $order)
            ->with('status', $order->fresh()?->status === PurchaseStatus::AwaitingPayment
                ? 'Thank you. We are waiting for your bank to confirm the payment, which usually takes a few seconds.'
                : 'Payment confirmed. Your money is held until you confirm delivery.');
    }

    public function confirm(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $account = $this->own($request, $order);

        return $this->attempt(fn () => $this->purchases->confirm($order, $account), 'Thank you. The merchant has been paid.');
    }

    public function issue(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $account = $this->own($request, $order);
        $reason = $request->string('reason')->toString();

        return $this->attempt(
            fn () => $this->purchases->raiseIssue($order, $account, $reason),
            'Your issue is with our team. The money stays held until they have looked at it.',
        );
    }

    /** The buyer's word on the agent's report. */
    public function inspection(Request $request, PurchaseOrder $order, ManageInspections $inspections): RedirectResponse
    {
        $account = $this->own($request, $order);
        $input = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);
        $inspection = Inspection::query()->where('purchase_order_id', $order->id)->firstOrFail();

        return $this->attempt(
            fn () => $inspections->decide($inspection, $account, (bool) $input['approve'], $input['note'] ?? null),
            (bool) $input['approve']
                ? 'Report approved. The business can send your order now.'
                : 'We have your concern. The money stays held while our team looks at it.',
        );
    }

    public function cancel(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $account = $this->own($request, $order);

        return $this->attempt(fn () => $this->purchases->cancel($order, $account), 'Order cancelled. Nothing was charged.');
    }

    private function own(Request $request, PurchaseOrder $order): PortalAccount
    {
        $account = $this->account($request);

        if ($order->buyer_account_id !== $account->id) {
            throw new NotFoundHttpException;
        }

        return $account;
    }

    /** @param  callable(): mixed  $action */
    private function attempt(callable $action, string $done): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('status', $done);
    }
}
