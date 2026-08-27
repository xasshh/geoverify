<?php

declare(strict_types=1);

namespace App\Domain\Party\Models;

use App\Domain\Party\Enums\PartyRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's access to one party.
 *
 * @property int $id
 * @property int $party_id
 * @property int $portal_account_id
 * @property PartyRole $role
 * @property int|null $invited_by
 * @property Carbon|null $invited_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
final class PartyUser extends Model
{
    protected $fillable = [
        'party_id', 'portal_account_id', 'role', 'invited_by',
        'invited_at', 'accepted_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => PartyRole::class,
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<PortalAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'portal_account_id');
    }

    /** Invited, accepted, and not since revoked. */
    public function isLive(): bool
    {
        return $this->accepted_at !== null && $this->revoked_at === null;
    }
}
