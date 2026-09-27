<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Commerce\Actions\ManagePurchase;
use App\Domain\Commerce\Actions\PresentPurchase;
use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Orders a buyer raised an issue on, for an admin to rule.
 *
 * Admin rather than supervisor for the reason escalations are: a ruling moves
 * somebody's money, one way or the other, and the person who decides it should
 * not be the person who deals with either side day to day.
 */
final class DisputeController
{
    public function index(PresentPurchase $present): Response
    {
        return Inertia::render('admin/Disputes', [
            'disputes' => PurchaseOrder::query()
                ->where('status', PurchaseStatus::Disputed->value)
                ->orderBy('disputed_at')
                ->limit(100)
                ->get()
                ->map(static fn (PurchaseOrder $o): array => $present($o))
                ->all(),
        ]);
    }

    public function rule(Request $request, PurchaseOrder $order, ManagePurchase $purchases): RedirectResponse
    {
        $input = $request->validate([
            'for' => ['required', Rule::in(['buyer', 'merchant'])],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $admin = $request->user();
        abort_unless($admin instanceof User, 403);

        try {
            $purchases->rule($order, $admin, $input['for'] === 'buyer', $input['note']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['note' => $e->getMessage()]);
        }

        return back()->with('status', $input['for'] === 'buyer'
            ? "{$order->reference} refunded. Send the refund from the provider's dashboard against the same reference."
            : "{$order->reference} released to the merchant.");
    }
}
