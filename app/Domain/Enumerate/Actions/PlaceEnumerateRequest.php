<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Enumerate\Jobs\RunRegistryChecksJob;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\EnumerateWallet;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A requester pays from the wallet for a check on a business.
 *
 * Spending credit already held is a movement inside the ledger, not money
 * arriving, so it needs no webhook: the wallet's liability shrinks and the
 * obligation to do the work grows by the same amount. The wallet row is locked
 * while the balance is read and spent, so two tabs paying at once cannot both
 * see the same naira.
 *
 * The subject is what the person picked from the search results. It is not
 * trusted for anything but naming the request: the registry lookups run after
 * this against the number, and the desk check compares what they say.
 */
final class PlaceEnumerateRequest
{
    private const COMPANY_TYPES = ['COMPANY', 'BUSINESS_NAME', 'INCORPORATED_TRUSTEES', 'LIMITED_PARTNERSHIP', 'LIMITED_LIABILITY_PARTNERSHIP'];

    public function __construct(
        private readonly ManageRequesterWallet $wallets,
        private readonly ReadEnumeratePrices $prices,
        private readonly PostTransaction $post,
        private readonly MintReference $mint,
    ) {}

    /**
     * @param  array{name: string, rcNumber: string, companyType: string, address?: string|null}  $subject
     */
    public function __invoke(PortalAccount $account, array $subject, Tier $tier, ?int $days = null, ?EnumerateWallet $wallet = null, ?int $batchId = null): EnumerateRequest
    {
        if ($tier === Tier::Activity && ! in_array($days, Tier::MONITORING_PERIODS, true)) {
            throw new RuntimeException('Choose 7, 14 or 30 days of monitoring.');
        }

        $days = $tier === Tier::Activity ? $days : null;
        $name = trim($subject['name']);
        $rc = (string) preg_replace('/\D+/', '', $subject['rcNumber']);

        if ($name === '' || $rc === '') {
            throw new RuntimeException('Pick the business from the register results first.');
        }

        if (! in_array($subject['companyType'], self::COMPANY_TYPES, true)) {
            throw new RuntimeException('That registration type is not one CAC uses.');
        }

        $price = $this->prices->priceMinor($tier, $days);
        $fee = min($price, $this->prices->priceMinor(Tier::Registry, null));
        // Their own, unless they are acting for an organisation: the caller
        // resolves that through EnumerateContext and hands the wallet in.
        $wallet ??= $this->wallets->walletFor($account);

        $request = DB::transaction(function () use ($account, $wallet, $tier, $days, $price, $fee, $name, $rc, $subject, $batchId): EnumerateRequest {
            EnumerateWallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            if ($this->wallets->balanceMinor($wallet) < $price) {
                throw new RuntimeException('Your wallet does not hold enough for this check. Fund it and try again.');
            }

            $request = $this->create([
                'wallet_id' => $wallet->id,
                'organisation_id' => $wallet->organisation_id,
                'enumerate_batch_id' => $batchId,
                'requested_by' => $account->id,
                'tier' => $tier,
                'monitoring_days' => $days,
                'price_minor' => $price,
                'registry_fee_minor' => $fee,
                'subject_name' => mb_substr($name, 0, 200),
                'rc_number' => $rc,
                'company_type' => $subject['companyType'],
                'registered_address' => isset($subject['address']) ? mb_substr((string) $subject['address'], 0, 300) : null,
                'status' => RequestStatus::Paid,
                'paid_at' => now(),
            ]);

            // A free check moves no money: the ledger refuses a leg of zero,
            // and there is nothing to hold.
            $transaction = $price === 0 ? null : ($this->post)(
                [
                    LedgerAccount::REQUESTER_WALLETS => $price,
                    LedgerAccount::CUSTOMER_FUNDS_HELD => -$price,
                ],
                LedgerEntry::REASON_WALLET_SPENT,
                null,
                "{$tier->short()} for {$request->reference}",
                enumerateRequestId: $request->id,
                walletId: $wallet->id,
            );

            VerificationEvent::recordForBuyer($request, 'enumerate.paid', $account, [
                'reference' => $request->reference,
                'tier' => $tier->value,
                'monitoring_days' => $days,
                'price_minor' => $price,
                'rc_number' => $rc,
                'transaction_uuid' => $transaction,
            ]);

            return $request;
        });

        RunRegistryChecksJob::dispatch($request->id)->afterCommit();

        return $request;
    }

    /** @param  array<string, mixed>  $attributes */
    private function create(array $attributes): EnumerateRequest
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(fn (): EnumerateRequest => EnumerateRequest::query()->create(
                    ['reference' => ($this->mint)('VRF')] + $attributes,
                ));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 4) {
                    throw $e;
                }
            }
        }
    }
}
