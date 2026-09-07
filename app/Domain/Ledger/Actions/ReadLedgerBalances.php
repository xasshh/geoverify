<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Actions;

use Illuminate\Support\Facades\DB;

/**
 * What each account holds, and whether the whole thing balances.
 *
 * The total across every account is zero in a sound double entry ledger. It is
 * worth asserting rather than assuming: the check costs one query and it is the
 * difference between finding a bug the week it appears and finding it when an
 * auditor does.
 */
final class ReadLedgerBalances
{
    /**
     * @return array{accounts: array<string, int>, ledger: list<array<string, mixed>>, total: int, balances: bool}
     */
    public function __invoke(): array
    {
        $rows = DB::select(<<<'SQL'
            select
                accounts.code,
                accounts.name,
                accounts.type,
                coalesce(sum(entries.amount_minor), 0) as balance_minor,
                count(entries.id) as entry_count
            from ledger_accounts accounts
            left join ledger_entries entries on entries.ledger_account_id = accounts.id
            group by accounts.id, accounts.code, accounts.name, accounts.type
            order by accounts.code
        SQL);

        $ledger = array_map(static fn (object $row): array => [
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'type' => (string) $row->type,
            'balanceMinor' => (int) $row->balance_minor,
            'entryCount' => (int) $row->entry_count,
        ], $rows);

        $total = array_sum(array_column($ledger, 'balanceMinor'));

        return [
            // Keyed by code, and carrying every account whether or not anything
            // has been posted to it. A caller asking what we are holding must
            // get zero rather than a missing key: an absent account and an empty
            // one mean the same thing, and only one of them is safe to read.
            'accounts' => array_column($ledger, 'balanceMinor', 'code'),
            'ledger' => $ledger,
            'total' => $total,
            'balances' => $total === 0,
        ];
    }
}
