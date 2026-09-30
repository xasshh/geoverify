<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Models\EnumerateWallet;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Party\Models\PortalAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A requester's wallet: finding it, and reading what is in it.
 *
 * Every number here is a sum over the ledger, attributed by the `wallet_id`
 * written on each leg. There is no balance column: a figure kept beside the
 * ledger is a second record of the same money, and the day they disagree
 * nobody can say which is right.
 *
 * The wallet account is a liability, stored negative, so it is negated to read
 * as the amount the person holds.
 */
final class ManageRequesterWallet
{
    public function walletFor(PortalAccount $account): EnumerateWallet
    {
        return EnumerateWallet::query()->firstOrCreate(['portal_account_id' => $account->id]);
    }

    public function walletForOrganisation(int $organisationId): EnumerateWallet
    {
        return EnumerateWallet::query()->firstOrCreate(['organisation_id' => $organisationId]);
    }

    public function balanceMinor(EnumerateWallet $wallet): int
    {
        $sum = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('ledger_accounts.code', LedgerAccount::REQUESTER_WALLETS)
            ->where('ledger_entries.wallet_id', $wallet->id)
            ->sum('ledger_entries.amount_minor');

        return -(int) $sum;
    }

    /**
     * The wallet's movements, newest first: top-ups in, requests out, and what
     * came back from a request that did not need all it was paid.
     *
     * @return list<array{kind: 'in'|'out', description: string, reference: string, at: string, amountMinor: int, tier: int|null}>
     */
    public function statement(EnumerateWallet $wallet, int $limit = 50): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT le.amount_minor, le.occurred_at, le.reason,
                   wf.reference AS funding_ref, wf.channel,
                   er.reference AS request_ref, er.subject_name, er.tier
              FROM ledger_entries le
              JOIN ledger_accounts la ON la.id = le.ledger_account_id
              LEFT JOIN wallet_fundings wf ON wf.id = le.wallet_funding_id
              LEFT JOIN enumerate_requests er ON er.id = le.enumerate_request_id
             WHERE la.code = ? AND le.wallet_id = ?
             ORDER BY le.occurred_at DESC, le.id DESC
             LIMIT ?
        SQL, [LedgerAccount::REQUESTER_WALLETS, $wallet->id, $limit]);

        return array_map(static function (object $r): array {
            // Negated: a credit to the liability is money in for the person.
            $amount = -(int) $r->amount_minor;
            $in = $amount > 0;

            $description = match (true) {
                $r->funding_ref !== null => 'Wallet funding · '.($r->channel === 'bank_transfer' ? 'bank transfer' : 'card'),
                $in => "Returned · {$r->subject_name}",
                default => "Tier {$r->tier} · {$r->subject_name}",
            };

            return [
                'kind' => $in ? 'in' : 'out',
                'description' => $description,
                'reference' => (string) ($r->funding_ref ?? $r->request_ref ?? ''),
                'at' => Carbon::parse((string) $r->occurred_at)->toIso8601String(),
                'amountMinor' => abs($amount),
                'tier' => $r->tier === null ? null : (int) $r->tier,
            ];
        }, $rows);
    }

    /**
     * This month so far: what was added, what was spent, and the spend by tier
     * for the bar under it. Spent is net of anything returned.
     *
     * @return array{addedMinor: int, spentMinor: int, byTier: array<int, int>}
     */
    public function thisMonth(EnumerateWallet $wallet): array
    {
        $start = Carbon::now(config('app.timezone'))->startOfMonth();

        $rows = DB::select(<<<'SQL'
            SELECT le.wallet_funding_id IS NOT NULL AS funding, er.tier, SUM(-le.amount_minor) AS amount
              FROM ledger_entries le
              JOIN ledger_accounts la ON la.id = le.ledger_account_id
              LEFT JOIN enumerate_requests er ON er.id = le.enumerate_request_id
             WHERE la.code = ? AND le.wallet_id = ? AND le.occurred_at >= ?
             GROUP BY 1, 2
        SQL, [LedgerAccount::REQUESTER_WALLETS, $wallet->id, $start]);

        $added = 0;
        $byTier = [1 => 0, 2 => 0, 3 => 0];

        foreach ($rows as $r) {
            if ((bool) $r->funding) {
                $added += (int) $r->amount;
            } elseif ($r->tier !== null) {
                $byTier[(int) $r->tier] -= (int) $r->amount;
            }
        }

        return ['addedMinor' => $added, 'spentMinor' => array_sum($byTier), 'byTier' => $byTier];
    }
}
