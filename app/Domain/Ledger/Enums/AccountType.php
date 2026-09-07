<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

/**
 * What kind of account this is, and therefore which sign increases it.
 *
 * Held rather than inferred. Whether a positive amount grows or shrinks an
 * account is a property of the account, and a reader who has to work it out
 * from the name will eventually get one wrong.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Income = 'income';
    case Expense = 'expense';

    /**
     * Whether a positive amount increases this account.
     *
     * Assets and expenses grow with a debit, which this ledger writes as a
     * positive. Liabilities and income grow with a credit, written negative.
     * One sign column rather than two, so a row cannot claim to be both.
     */
    public function increasesWithPositive(): bool
    {
        return $this === self::Asset || $this === self::Expense;
    }
}
