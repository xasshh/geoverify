<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enumerate;

use App\Domain\Enumerate\Actions\EnumerateContext;
use App\Domain\Enumerate\Actions\FundRequesterWallet;
use App\Domain\Enumerate\Actions\ManageRequesterWallet;
use App\Domain\Enumerate\Actions\ReadEnumeratePrices;
use App\Domain\Enumerate\Models\WalletFunding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The requester's wallet: the balance, the statement, and topping it up.
 *
 * Topping up hands the person to the provider and marks nothing. The page they
 * come back to reads the funding as the webhook left it, which may be a moment
 * behind the browser; it says so rather than guessing.
 */
final class WalletController
{
    public function __construct(
        private readonly EnumerateController $enumerate,
        private readonly ManageRequesterWallet $wallets,
        private readonly EnumerateContext $context,
    ) {}

    public function show(Request $request, ReadEnumeratePrices $prices): Response
    {
        $account = EnumerateController::account($request);
        $wallet = $this->context->wallet($request, $account);

        return Inertia::render('enumerate/Wallet', [
            'frame' => $this->enumerate->frame($request, $account),
            'prices' => $prices->list(),
            'month' => $this->wallets->thisMonth($wallet),
            'statement' => $this->wallets->statement($wallet),
            'pending' => WalletFunding::query()
                ->where('wallet_id', $wallet->id)
                ->where('status', WalletFunding::PENDING)
                ->where('created_at', '>=', now()->subDay())
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(static fn (WalletFunding $f): array => [
                    'reference' => $f->reference,
                    'amountMinor' => $f->amount_minor,
                    'channel' => $f->channel,
                    'at' => $f->created_at?->toIso8601String(),
                ])->values()->all(),
            'minimumMinor' => FundRequesterWallet::MINIMUM_MINOR,
        ]);
    }

    public function fund(Request $request, FundRequesterWallet $fund): HttpResponse|RedirectResponse
    {
        $account = EnumerateController::account($request);
        $input = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'channel' => ['required', 'in:card,bank_transfer'],
        ]);

        $member = $this->context->member($request, $account);

        if ($member !== null && ! $member->may('fund')) {
            return back()->withErrors(['amount' => 'Only an admin or a project lead funds the organisation wallet.']);
        }

        try {
            $started = $fund(
                $account,
                (int) round(((float) $input['amount']) * 100),
                $input['channel'],
                route('enumerate.wallet.return'),
                $this->context->wallet($request, $account),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        // Off to the provider's page: a full navigation, not an Inertia visit.
        return Inertia::location($started['url']);
    }

    /** Where the provider sends the person back. Reads; never records. */
    public function return(Request $request): RedirectResponse
    {
        EnumerateController::account($request);

        return redirect()->route('enumerate.wallet')->with(
            'status',
            'Thank you. Your top-up shows here as soon as the payment provider confirms it, usually within a minute.',
        );
    }
}
