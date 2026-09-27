<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\Payout;
use App\Domain\Commerce\Models\PayoutAccount;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A merchant takes their money out.
 *
 * Asking reserves the amount: it leaves the available balance for "in transit"
 * in the same transaction that checks it was there, with the party row locked,
 * so two withdrawals at once cannot both spend the same naira. The provider is
 * then asked for the transfer. The cash is recorded as gone only when the
 * provider's signed webhook says the transfer succeeded, and a failure or a
 * reversal puts the amount back where it came from.
 *
 * The bank account is resolved with the provider before it is saved, so the
 * name the merchant sees is the name the bank holds, and what is kept is the
 * provider's recipient code and the last four digits.
 */
final class ManagePayouts
{
    public function __construct(
        private readonly PostTransaction $post,
        private readonly ReadWallet $wallet,
    ) {}

    public function saveAccount(PartyUser $membership, string $bankCode, string $accountNumber): PayoutAccount
    {
        $this->assertOwner($membership);

        $accountNumber = preg_replace('/\D/', '', $accountNumber) ?? '';

        if (strlen($accountNumber) !== 10) {
            throw new RuntimeException('A Nigerian account number is ten digits.');
        }

        $resolved = $this->provider()->get('https://api.paystack.co/bank/resolve', [
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
        ]);

        $name = $resolved->json('data.account_name');

        if (! $resolved->successful() || ! is_string($name) || $name === '') {
            throw new RuntimeException('The bank did not recognise that account number.');
        }

        $recipient = $this->provider()->post('https://api.paystack.co/transferrecipient', [
            'type' => 'nuban',
            'name' => $name,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'currency' => 'NGN',
        ]);

        $code = $recipient->json('data.recipient_code');

        if (! $recipient->successful() || ! is_string($code)) {
            throw new RuntimeException('The payment provider would not register that account for transfers.');
        }

        $bankName = $recipient->json('data.details.bank_name');

        return DB::transaction(function () use ($membership, $bankCode, $accountNumber, $name, $code, $bankName): PayoutAccount {
            PayoutAccount::query()
                ->where('party_id', $membership->party_id)
                ->where('status', PayoutAccount::STATUS_ACTIVE)
                ->update(['status' => PayoutAccount::STATUS_RETIRED]);

            $account = PayoutAccount::query()->create([
                'party_id' => $membership->party_id,
                'bank_code' => $bankCode,
                'bank_name' => is_string($bankName) ? $bankName : $bankCode,
                'account_last4' => substr($accountNumber, -4),
                'account_name' => $name,
                'recipient_code' => $code,
                'status' => PayoutAccount::STATUS_ACTIVE,
                'added_by' => $membership->portal_account_id,
            ]);

            VerificationEvent::recordForParty($account, 'payout_account.saved', $membership->party()->firstOrFail(), [
                'bank_code' => $bankCode,
                'account_last4' => $account->account_last4,
                'by_account' => $membership->portal_account_id,
            ]);

            return $account;
        });
    }

    public function request(PartyUser $membership, int $amountMinor): Payout
    {
        $this->assertOwner($membership);

        $minimum = (int) config('geoverify.commerce.minimum_payout_minor', 0);

        if ($amountMinor < max(1, $minimum)) {
            throw new RuntimeException(sprintf('The smallest withdrawal is ₦%s.', number_format(intdiv($minimum, 100))));
        }

        $payout = DB::transaction(function () use ($membership, $amountMinor): Payout {
            // The party row is the lock: every withdrawal for this business
            // queues behind it, so the balance read below is still true when
            // the reservation is posted.
            Party::query()->whereKey($membership->party_id)->lockForUpdate()->firstOrFail();

            $account = PayoutAccount::query()
                ->where('party_id', $membership->party_id)
                ->where('status', PayoutAccount::STATUS_ACTIVE)
                ->first();

            if (! $account instanceof PayoutAccount) {
                throw new RuntimeException('Add the bank account to pay into first.');
            }

            $available = ($this->wallet)($membership->party_id)['availableMinor'];

            if ($amountMinor > $available) {
                throw new RuntimeException('That is more than your available balance.');
            }

            $payout = Payout::query()->create([
                'reference' => 'gvpo-'.Str::lower((string) Str::ulid()),
                'party_id' => $membership->party_id,
                'payout_account_id' => $account->id,
                'amount_minor' => $amountMinor,
                'currency' => 'NGN',
                'status' => Payout::STATUS_REQUESTED,
                'requested_by' => $membership->portal_account_id,
            ]);

            ($this->post)(
                [
                    LedgerAccount::MERCHANT_BALANCES => $amountMinor,
                    LedgerAccount::PAYOUTS_IN_TRANSIT => -$amountMinor,
                ],
                LedgerEntry::REASON_PAYOUT_REQUESTED,
                null,
                "Withdrawal {$payout->reference}",
                payoutId: $payout->id,
            );

            VerificationEvent::recordForParty($payout, 'payout.requested', $membership->party()->firstOrFail(), [
                'reference' => $payout->reference,
                'amount_minor' => $amountMinor,
                'by_account' => $membership->portal_account_id,
            ]);

            return $payout;
        });

        // Outside the transaction: a slow provider must not hold the party
        // lock, and the reservation is already safely recorded.
        $response = $this->provider()->post('https://api.paystack.co/transfer', [
            'source' => 'balance',
            'amount' => $payout->amount_minor,
            'recipient' => $payout->account?->recipient_code,
            'reference' => $payout->reference,
            'reason' => 'GeoVerify withdrawal',
        ]);

        if (! $response->successful()) {
            // Refused outright, so no transfer exists for a webhook to settle.
            // The reservation is returned now rather than left in transit.
            $message = is_string($response->json('message')) ? $response->json('message') : 'no reason given';

            return $this->settle($payout->reference, false, "Refused by the provider: {$message}");
        }

        $code = $response->json('data.transfer_code');
        $payout->update(['transfer_code' => is_string($code) ? $code : null]);

        return $payout;
    }

    /**
     * The provider's word on a transfer. Only HandlePaymentWebhook calls this
     * with $succeeded true: the cash leaves the ledger on a signed event or not
     * at all. Idempotent: a payout already settled is returned as it stands.
     */
    public function settle(string $reference, bool $succeeded, ?string $reason = null, ?Carbon $at = null): Payout
    {
        return DB::transaction(function () use ($reference, $succeeded, $reason, $at): Payout {
            /** @var Payout|null $payout */
            $payout = Payout::query()->where('reference', $reference)->lockForUpdate()->first();

            if (! $payout instanceof Payout) {
                throw new RuntimeException("There is no withdrawal called {$reference}.");
            }

            if ($payout->status !== Payout::STATUS_REQUESTED) {
                return $payout;
            }

            $legs = $succeeded
                ? [LedgerAccount::PAYOUTS_IN_TRANSIT => $payout->amount_minor, LedgerAccount::CASH => -$payout->amount_minor]
                : [LedgerAccount::PAYOUTS_IN_TRANSIT => $payout->amount_minor, LedgerAccount::MERCHANT_BALANCES => -$payout->amount_minor];

            $transaction = ($this->post)(
                $legs,
                $succeeded ? LedgerEntry::REASON_PAYOUT_SENT : LedgerEntry::REASON_PAYOUT_RETURNED,
                null,
                ($succeeded ? 'Withdrawal paid ' : 'Withdrawal returned ').$payout->reference,
                $at,
                payoutId: $payout->id,
            );

            $payout->update([
                'status' => $succeeded ? Payout::STATUS_PAID : Payout::STATUS_RETURNED,
                'failure_reason' => $succeeded ? null : mb_substr($reason ?? 'Not given', 0, 280),
                'settled_at' => $at ?? now(),
            ]);

            VerificationEvent::record($payout, $succeeded ? 'payout.paid' : 'payout.returned', null, [
                'reference' => $payout->reference,
                'amount_minor' => $payout->amount_minor,
                'reason' => $reason,
                'transaction_uuid' => $transaction,
            ], VerificationEvent::ACTOR_EXTERNAL);

            if (! $succeeded) {
                Log::warning('A merchant withdrawal came back.', ['reference' => $payout->reference, 'reason' => $reason]);
            }

            return $payout;
        });
    }

    /**
     * The banks a merchant can choose from, as the provider lists them.
     *
     * @return list<array{code: string, name: string}>
     */
    public function banks(): array
    {
        $cached = cache()->get('paystack.banks.ng');

        if (is_array($cached) && $cached !== []) {
            /** @var list<array{code: string, name: string}> $cached */
            return $cached;
        }

        $response = $this->provider()->get('https://api.paystack.co/bank', ['country' => 'nigeria', 'perPage' => 200]);
        $rows = $response->json('data');
        $banks = [];

        foreach (is_array($rows) && $response->successful() ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['code'] ?? null) && is_string($row['name'] ?? null)) {
                $banks[] = ['code' => $row['code'], 'name' => $row['name']];
            }
        }

        // Only a real answer is kept. An outage cached for a day would leave
        // every merchant with an empty list long after the provider recovered.
        if ($banks !== []) {
            cache()->put('paystack.banks.ng', $banks, now()->addDay());
        }

        return $banks;
    }

    private function assertOwner(PartyUser $membership): void
    {
        if (! $membership->isLive() || ! $membership->role->withdraws()) {
            throw new RuntimeException('Only an owner can move money out of the business.');
        }
    }

    private function provider(): PendingRequest
    {
        $secret = config('services.paystack.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('PAYSTACK_SECRET_KEY is not set, so no money can be moved.');
        }

        return Http::withToken($secret)->acceptJson()->timeout(20);
    }
}
