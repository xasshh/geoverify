<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Models;

use App\Domain\Party\Models\PortalAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prepaid credit for verifications. Holds no balance: see ReadRequesterWallet.
 *
 * @property int $id
 * @property int|null $portal_account_id
 * @property int|null $organisation_id
 * @property-read PortalAccount|null $account
 * @property-read EnumerateOrganisation|null $organisation
 */
final class EnumerateWallet extends Model
{
    protected $fillable = ['portal_account_id', 'organisation_id'];

    /** @return BelongsTo<EnumerateOrganisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(EnumerateOrganisation::class, 'organisation_id');
    }

    /** @return BelongsTo<PortalAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'portal_account_id');
    }
}
