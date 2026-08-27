<?php

declare(strict_types=1);

namespace App\Domain\Claim\Models;

use App\Domain\Claim\Enums\ClaimRelationship;
use App\Domain\Party\Models\Party;
use App\Domain\Registry\Models\Enterprise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Who controls a listing, now.
 *
 * The row every authorisation check reads. One active row per enterprise, held
 * by a partial unique index rather than by the code that writes it.
 *
 * @property int $id
 * @property int $party_id
 * @property int $enterprise_id
 * @property ClaimRelationship $relationship
 * @property string $established_via
 * @property Carbon $established_at
 * @property int|null $claim_id
 * @property string $status
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 */
final class PartyBusiness extends Model
{
    public const VIA_CLAIM = 'claim';

    public const VIA_SELF_REGISTRATION = 'self_registration';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'party_id', 'enterprise_id', 'relationship', 'established_via',
        'established_at', 'claim_id', 'status', 'revoked_at', 'revoked_reason',
    ];

    protected function casts(): array
    {
        return [
            'relationship' => ClaimRelationship::class,
            'established_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Claim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }
}
