<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Actions;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Asks the payment provider what it collected, and compares that to what we
 * wrote down.
 *
 * Two records of the same money kept by two organisations will disagree
 * eventually. A webhook that never arrived, a charge captured after our
 * timeout, a manual refund somebody issued from the provider's dashboard: none
 * of these announce themselves, and every one of them leaves our ledger quietly
 * wrong. The failure mode is not a crash, it is a number in a report months
 * later that nobody can explain, so the answer is to ask the question daily and
 * make the difference visible while it is still one transaction.
 *
 * What is compared is deliberately narrow: successful charges against the cash
 * legs we posted on receiving them. Our CASH account is named "cash at payment
 * provider" and that is exactly what it is, so the provider's own record of
 * collections is the like-for-like counterpart. Settlement to a bank account is
 * a further hop we do not model, because there is no bank account in this chart
 * of accounts to reconcile it against.
 *
 * Refunds are counted and reported but not matched. They leave our cash on our
 * side and appear in the provider's refund record rather than its transaction
 * list, so pairing them here would compare two different things and call the
 * difference drift.
 */
final class ReconcileWithProvider
{
    /** How many the provider returns per page. Its maximum is higher; this is polite. */
    private const PER_PAGE = 100;

    /** A runaway pager is worse than an incomplete answer, and says so. */
    private const MAX_PAGES = 200;

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     provider_total_minor: int,
     *     ledger_total_minor: int,
     *     drift_minor: int,
     *     refunds_minor: int,
     *     provider_count: int,
     *     ledger_count: int,
     *     missing_from_ledger: list<array{reference: string, amount_minor: int, paid_at: string|null}>,
     *     missing_from_provider: list<array{reference: string, amount_minor: int}>,
     *     mismatched: list<array{reference: string, provider_minor: int, ledger_minor: int}>
     * }
     */
    public function __invoke(Carbon $from, Carbon $to): array
    {
        $provider = $this->provider($from, $to);
        $ledger = $this->ledger($from, $to);

        $missingFromLedger = [];
        $mismatched = [];

        foreach ($provider as $reference => $charge) {
            if (! array_key_exists($reference, $ledger)) {
                $missingFromLedger[] = [
                    'reference' => $reference,
                    'amount_minor' => $charge['amount_minor'],
                    'paid_at' => $charge['paid_at'],
                ];

                continue;
            }

            if ($ledger[$reference] !== $charge['amount_minor']) {
                $mismatched[] = [
                    'reference' => $reference,
                    'provider_minor' => $charge['amount_minor'],
                    'ledger_minor' => $ledger[$reference],
                ];
            }
        }

        $missingFromProvider = [];

        foreach ($ledger as $reference => $amount) {
            if (! array_key_exists($reference, $provider)) {
                $missingFromProvider[] = ['reference' => $reference, 'amount_minor' => $amount];
            }
        }

        $providerTotal = array_sum(array_column($provider, 'amount_minor'));
        $ledgerTotal = array_sum($ledger);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'provider_total_minor' => $providerTotal,
            'ledger_total_minor' => $ledgerTotal,

            // Signed, and the sign is the story: positive means the provider
            // collected money we never recorded, which is the direction that
            // costs a customer their visit.
            'drift_minor' => $providerTotal - $ledgerTotal,

            'refunds_minor' => $this->refunds($from, $to),
            'provider_count' => count($provider),
            'ledger_count' => count($ledger),
            'missing_from_ledger' => $missingFromLedger,
            'missing_from_provider' => $missingFromProvider,
            'mismatched' => $mismatched,
        ];
    }

    /**
     * Successful charges the provider says it took, keyed by our reference.
     *
     * Our order reference is the provider's reference, which is what makes this
     * comparison possible at all: there is no mapping table between the two
     * sides and therefore nothing for them to drift apart on.
     *
     * @return array<string, array{amount_minor: int, paid_at: string|null}>
     */
    private function provider(Carbon $from, Carbon $to): array
    {
        $secret = config('services.paystack.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException(
                'PAYSTACK_SECRET_KEY is not set, so there is nothing to reconcile against.',
            );
        }

        $charges = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = Http::withToken($secret)
                ->acceptJson()
                ->timeout(30)
                ->get('https://api.paystack.co/transaction', [
                    'status' => 'success',
                    'perPage' => self::PER_PAGE,
                    'page' => $page,
                    'from' => $from->toIso8601String(),
                    'to' => $to->toIso8601String(),
                ]);

            if (! $response->successful()) {
                throw new RuntimeException(sprintf(
                    'The payment provider refused the transaction list on page %d: %s',
                    $page,
                    is_string($response->json('message')) ? $response->json('message') : 'no reason given',
                ));
            }

            $data = $response->json('data');

            if (! is_array($data) || $data === []) {
                break;
            }

            foreach ($data as $charge) {
                if (! is_array($charge)) {
                    continue;
                }

                $reference = $charge['reference'] ?? null;

                if (! is_string($reference)) {
                    continue;
                }

                // Summed rather than assigned. A reference should appear once,
                // and a provider that sends it twice is itself a finding: the
                // total then disagrees with our single posting and the drift
                // line says so, which is better than the second one silently
                // overwriting the first.
                $charges[$reference] = [
                    'amount_minor' => (int) ($charges[$reference]['amount_minor'] ?? 0) + (int) ($charge['amount'] ?? 0),
                    'paid_at' => is_string($charge['paid_at'] ?? null) ? $charge['paid_at'] : null,
                ];
            }

            if (count($data) < self::PER_PAGE) {
                break;
            }
        }

        return $charges;
    }

    /**
     * What we posted to cash on receiving money, keyed by order reference.
     *
     * @return array<string, int>
     */
    private function ledger(Carbon $from, Carbon $to): array
    {
        // Both kinds of order the provider collects for, by the reference it
        // knows them by. A product order left out of this would show every
        // buyer's payment as money we never recorded.
        /** @var array<string, int> $rows */
        $rows = LedgerEntry::query()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->leftJoin('verification_orders', 'verification_orders.id', '=', 'ledger_entries.verification_order_id')
            ->leftJoin('purchase_orders', 'purchase_orders.id', '=', 'ledger_entries.purchase_order_id')
            ->where('ledger_accounts.code', LedgerAccount::CASH)
            ->where('ledger_entries.reason', LedgerEntry::REASON_PAYMENT_RECEIVED)
            ->whereBetween('ledger_entries.occurred_at', [$from, $to])
            ->whereRaw('COALESCE(verification_orders.reference, purchase_orders.reference) IS NOT NULL')
            ->groupByRaw('COALESCE(verification_orders.reference, purchase_orders.reference)')
            ->selectRaw('COALESCE(verification_orders.reference, purchase_orders.reference) AS reference')
            ->selectRaw('SUM(ledger_entries.amount_minor) AS amount_minor')
            ->pluck('amount_minor', 'reference')
            ->map(static fn ($amount): int => (int) $amount)
            ->all();

        return $rows;
    }

    /** Money handed back in the period, reported rather than matched. */
    private function refunds(Carbon $from, Carbon $to): int
    {
        return (int) DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('ledger_accounts.code', LedgerAccount::CASH)
            ->where('ledger_entries.reason', LedgerEntry::REASON_REFUNDED)
            ->whereBetween('ledger_entries.occurred_at', [$from, $to])
            ->sum('ledger_entries.amount_minor');
    }
}
