<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A supervisor's reading of the registry answers: passed, or failed and why.
 *
 * The money follows the decision, and only as far as the work went.
 *
 *  - Tier 1 passed or failed: the desk check was the whole job, so the fee is
 *    earned either way. A failure is a finding, not a failure of service.
 *  - Tier 2 or 3 passed: nothing is earned yet. The request waits for an
 *    officer and the money stays held until the visit is accepted.
 *  - Tier 2 or 3 failed: no officer goes to a business that is not on the
 *    register as described. The desk check's fee is earned and the rest goes
 *    back to the wallet it came from.
 */
final class DecideDeskCheck
{
    public function __construct(
        private readonly PostTransaction $post,
        private readonly ScoreEnumerateRequest $score,
    ) {}

    public function __invoke(EnumerateRequest $request, User $supervisor, bool $passed, ?string $reason): EnumerateRequest
    {
        if (! $supervisor->supervises()) {
            throw new RuntimeException('Only a supervisor decides a desk check.');
        }

        $reason = $reason === null ? null : trim($reason);

        if (! $passed && ($reason === null || mb_strlen($reason) < 5)) {
            throw new RuntimeException('Say why it failed. The requester reads this on their report.');
        }

        return DB::transaction(function () use ($request, $supervisor, $passed, $reason): EnumerateRequest {
            /** @var EnumerateRequest $fresh */
            $fresh = EnumerateRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== RequestStatus::RegistryCheck) {
                throw new RuntimeException($fresh->status === RequestStatus::Paid
                    ? 'The registry has not answered yet. Run the lookups first.'
                    : 'That desk check has already been decided.');
            }

            $finishes = ! $passed || $fresh->tier === Tier::Registry;

            $fresh->update([
                'registry_outcome' => $passed ? 'passed' : 'failed',
                'registry_reason' => $reason === '' ? null : ($reason === null ? null : mb_substr($reason, 0, 300)),
                'desk_checked_by' => $supervisor->id,
                'desk_checked_at' => now(),
                'status' => match (true) {
                    ! $passed => RequestStatus::Failed,
                    $fresh->tier === Tier::Registry => RequestStatus::Passed,
                    default => RequestStatus::AwaitingAgent,
                },
                'completed_at' => $finishes ? now() : null,
            ]);

            $transaction = $finishes ? $this->settle($fresh) : null;

            if ($finishes) {
                $this->score->stamp($fresh);
            }

            VerificationEvent::record($fresh, $passed ? 'enumerate.desk_passed' : 'enumerate.desk_failed', $supervisor, [
                'reason' => $reason,
                'tier' => $fresh->tier->value,
                'transaction_uuid' => $transaction,
            ]);

            return $fresh;
        });
    }

    /** The fee earned, and anything not worked for returned to the wallet. */
    private function settle(EnumerateRequest $request): string
    {
        $returned = $request->price_minor - $request->registry_fee_minor;

        $legs = [
            LedgerAccount::CUSTOMER_FUNDS_HELD => $request->price_minor,
            LedgerAccount::VERIFICATION_INCOME => -$request->registry_fee_minor,
        ];

        if ($returned > 0) {
            $legs[LedgerAccount::REQUESTER_WALLETS] = -$returned;
        }

        return ($this->post)(
            $legs,
            $returned > 0 ? LedgerEntry::REASON_REFUNDED : LedgerEntry::REASON_WORK_COMPLETED,
            null,
            $returned > 0
                ? "Desk check for {$request->reference}; the visit was not needed"
                : "Desk check for {$request->reference}",
            enumerateRequestId: $request->id,
            walletId: $request->wallet_id,
        );
    }
}
