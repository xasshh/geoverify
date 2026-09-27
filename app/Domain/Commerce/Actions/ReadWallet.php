<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Ledger\Models\LedgerAccount;
use Illuminate\Support\Facades\DB;

/**
 * A merchant's balances, read from the ledger rather than kept beside it.
 *
 * There is no balance column anywhere. A number stored next to the ledger is a
 * second record of the same money, and the day they disagree nobody can say
 * which is right. These are sums over entries, and each is attributed to the
 * party through the order or the payout the entry is about.
 *
 * Liabilities are credits, stored negative, so each is negated to read as the
 * amount owed.
 */
final class ReadWallet
{
    /** @return array{heldMinor: int, availableMinor: int, inTransitMinor: int} */
    public function __invoke(int $partyId): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT
              -COALESCE(SUM(le.amount_minor) FILTER (WHERE la.code = :held), 0)      AS held,
              -COALESCE(SUM(le.amount_minor) FILTER (WHERE la.code = :available), 0) AS available,
              -COALESCE(SUM(le.amount_minor) FILTER (WHERE la.code = :transit), 0)   AS transit
            FROM ledger_entries le
            JOIN ledger_accounts la ON la.id = le.ledger_account_id
            LEFT JOIN purchase_orders po ON po.id = le.purchase_order_id
            LEFT JOIN payouts p ON p.id = le.payout_id
            WHERE la.code IN (:held2, :available2, :transit2)
              AND (po.seller_party_id = :party OR p.party_id = :party2)
        SQL, [
            'held' => LedgerAccount::BUYER_FUNDS_HELD,
            'available' => LedgerAccount::MERCHANT_BALANCES,
            'transit' => LedgerAccount::PAYOUTS_IN_TRANSIT,
            'held2' => LedgerAccount::BUYER_FUNDS_HELD,
            'available2' => LedgerAccount::MERCHANT_BALANCES,
            'transit2' => LedgerAccount::PAYOUTS_IN_TRANSIT,
            'party' => $partyId,
            'party2' => $partyId,
        ]);

        return [
            'heldMinor' => (int) $row->held,
            'availableMinor' => (int) $row->available,
            'inTransitMinor' => (int) $row->transit,
        ];
    }
}
