<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Commerce\Actions\ManagePayouts;
use App\Domain\Commerce\Actions\ReadWallet;
use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\Payout;
use App\Domain\Commerce\Models\PayoutAccount;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Http\Controllers\Portal\Concerns\ActsForBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Held, available, and taking it out. Balances are read from the ledger each time. */
final class WalletController
{
    use ActsForBusiness;

    public function __construct(private readonly ManagePayouts $payouts) {}

    public function show(Request $request, ReadWallet $wallet): Response
    {
        $membership = $this->membership($request);
        $party = $membership->party_id;
        $canWithdraw = $membership->role->withdraws();

        $account = PayoutAccount::query()
            ->where('party_id', $party)
            ->where('status', PayoutAccount::STATUS_ACTIVE)
            ->first();

        $balances = $wallet($party);

        return Inertia::render('portal/Wallet', [
            'heldNaira' => intdiv($balances['heldMinor'], 100),
            'availableNaira' => intdiv($balances['availableMinor'], 100),
            'inTransitNaira' => intdiv($balances['inTransitMinor'], 100),
            'awaitingRelease' => PurchaseOrder::query()
                ->where('seller_party_id', $party)
                ->whereIn('status', [PurchaseStatus::Held->value, PurchaseStatus::Dispatched->value, PurchaseStatus::Disputed->value])
                ->count(),
            'minimumNaira' => intdiv((int) config('geoverify.commerce.minimum_payout_minor', 0), 100),
            'account' => $account === null ? null : [
                'bank' => $account->bank_name,
                'last4' => $account->account_last4,
                'name' => $account->account_name,
            ],
            'canWithdraw' => $canWithdraw,
            // Only asked of the provider when somebody could use the answer.
            'banks' => $canWithdraw ? $this->banksOrNothing() : [],
            'payouts' => Payout::query()
                ->with('account:id,bank_name,account_last4')
                ->where('party_id', $party)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(static fn (Payout $p): array => [
                    'reference' => $p->reference,
                    'amountNaira' => intdiv($p->amount_minor, 100),
                    'status' => $p->status,
                    'to' => $p->account === null ? null : "{$p->account->bank_name} ••••{$p->account->account_last4}",
                    'reason' => $p->failure_reason,
                    'requestedAt' => $p->created_at?->toIso8601String(),
                    'settledAt' => $p->settled_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    public function saveAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bank_code' => ['required', 'string', 'max:12'],
            'account_number' => ['required', 'string', 'max:20'],
        ]);

        try {
            $saved = $this->payouts->saveAccount($this->membership($request), $validated['bank_code'], $validated['account_number']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['account_number' => $e->getMessage()]);
        }

        return back()->with('status', "Withdrawals will go to {$saved->account_name}, {$saved->bank_name} ••••{$saved->account_last4}.");
    }

    public function withdraw(Request $request): RedirectResponse
    {
        $validated = $request->validate(['amount' => ['required', 'integer', 'min:1']]);

        try {
            $payout = $this->payouts->request($this->membership($request), (int) $validated['amount'] * 100);
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        if ($payout->status === Payout::STATUS_RETURNED) {
            return back()->withErrors(['amount' => 'The transfer could not be started, and your balance is as it was. '.$payout->failure_reason]);
        }

        return back()->with('status', 'Withdrawal requested. It usually arrives within minutes; this page will say when it has.');
    }

    /** @return list<array{code: string, name: string}> */
    private function banksOrNothing(): array
    {
        try {
            return $this->payouts->banks();
        } catch (RuntimeException) {
            return [];
        }
    }
}
