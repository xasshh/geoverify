<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/** "Pay with": the provider is told to offer only the one chosen. */
enum PayChannel: string
{
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Ussd = 'ussd';

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Card',
            self::BankTransfer => 'Bank transfer',
            self::Ussd => 'USSD',
        };
    }
}
