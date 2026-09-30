<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Models;

use App\Domain\Party\Models\PortalAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message in a support thread. Written once; the database refuses edits.
 *
 * @property int $id
 * @property int $enumerate_ticket_id
 * @property int|null $portal_account_id
 * @property int|null $user_id
 * @property string $body
 * @property int|null $refund_minor
 * @property Carbon $created_at
 * @property-read User|null $staff
 * @property-read PortalAccount|null $account
 */
final class EnumerateTicketMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['enumerate_ticket_id', 'portal_account_id', 'user_id', 'body', 'refund_minor', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'refund_minor' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<PortalAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'portal_account_id');
    }
}
