<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Where a merchant is paid: the provider's recipient code and enough to
 * recognise the account by, never the full number.
 *
 * @property int $id
 * @property int $party_id
 * @property string $bank_code
 * @property string $bank_name
 * @property string $account_last4
 * @property string $account_name
 * @property string $recipient_code
 * @property string $status
 */
final class PayoutAccount extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'party_id', 'bank_code', 'bank_name', 'account_last4', 'account_name', 'recipient_code', 'status', 'added_by',
    ];
}
