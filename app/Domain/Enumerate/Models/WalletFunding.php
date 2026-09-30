<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money a person asked to add to their wallet. It counts once the provider's
 * signed webhook says it arrived, and not on anything the browser reports.
 *
 * @property int $id
 * @property string $reference
 * @property int $wallet_id
 * @property int $portal_account_id
 * @property int $amount_minor
 * @property string $channel
 * @property string $status
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property-read EnumerateWallet|null $wallet
 */
final class WalletFunding extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const CHANNELS = ['card', 'bank_transfer'];

    protected $fillable = ['reference', 'wallet_id', 'portal_account_id', 'amount_minor', 'channel', 'status', 'paid_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<EnumerateWallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(EnumerateWallet::class, 'wallet_id');
    }
}
