<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Models\EnumerateWallet;
use App\Domain\Enumerate\Models\WalletFunding;
use App\Domain\Party\Models\PortalAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Starting a top-up: a pending row, and a hand to the payment provider.
 *
 * Marks nothing paid. The wallet grows only in RecordWalletFunding, which is
 * reachable from the signed webhook and nowhere else; this returns the
 * provider's page and the reference the webhook will name.
 */
final class FundRequesterWallet
{
    /** The smallest top-up: one Tier 1 check (decision 7). */
    public const MINIMUM_MINOR = 150_000;

    /** A ceiling so a mistyped zero is a question rather than a charge. */
    public const MAXIMUM_MINOR = 500_000_000;

    public function __construct(
        private readonly ManageRequesterWallet $wallets,
        private readonly MintReference $mint,
    ) {}

    /** @return array{funding: WalletFunding, url: string} */
    public function __invoke(PortalAccount $account, int $amountMinor, string $channel, string $callbackUrl, ?EnumerateWallet $wallet = null): array
    {
        if ($amountMinor < self::MINIMUM_MINOR) {
            throw new RuntimeException('The smallest top-up is ₦1,500.');
        }

        if ($amountMinor > self::MAXIMUM_MINOR) {
            throw new RuntimeException('That is more than a wallet takes in one go. Add it in smaller amounts.');
        }

        if (! in_array($channel, WalletFunding::CHANNELS, true)) {
            throw new RuntimeException('Choose card or bank transfer.');
        }

        $secret = config('services.paystack.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Payments are not switched on here yet, so the wallet cannot be funded.');
        }

        $funding = $this->create($account, $amountMinor, $channel, $wallet);

        $response = Http::withToken($secret)
            ->acceptJson()
            ->timeout(20)
            ->post('https://api.paystack.co/transaction/initialize', [
                // Paystack needs an address for its receipt. A requester who
                // gave none gets a placeholder on our domain that goes nowhere.
                'email' => $account->email ?? "wallet-{$account->id}@accounts.geoverify.ng",
                'amount' => $amountMinor,
                'currency' => 'NGN',
                'reference' => $funding->reference,
                'callback_url' => $callbackUrl,
                'channels' => [$channel],
                'metadata' => ['kind' => 'wallet_funding', 'wallet_funding_id' => $funding->id],
            ]);

        $url = $response->json('data.authorization_url');

        if (! $response->successful() || ! is_string($url)) {
            throw new RuntimeException(
                'The payment provider did not give us a payment page: '
                .(is_string($response->json('message')) ? $response->json('message') : 'no reason given'),
            );
        }

        return ['funding' => $funding, 'url' => $url];
    }

    private function create(PortalAccount $account, int $amountMinor, string $channel, ?EnumerateWallet $wallet): WalletFunding
    {
        $wallet ??= $this->wallets->walletFor($account);

        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(fn (): WalletFunding => WalletFunding::query()->create([
                    'reference' => ($this->mint)('FND', 4),
                    'wallet_id' => $wallet->id,
                    'portal_account_id' => $account->id,
                    'amount_minor' => $amountMinor,
                    'channel' => $channel,
                    'status' => WalletFunding::PENDING,
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 4) {
                    throw $e;
                }
            }
        }
    }
}
