<?php

declare(strict_types=1);

namespace App\Domain\Party\Models;

use App\Domain\Party\Enums\PartyKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The accountable entity behind everything done on the portal.
 *
 * @property int $id
 * @property string $code
 * @property PartyKind $kind
 * @property string $display_name
 * @property string|null $legal_name
 * @property string $primary_phone
 * @property string|null $primary_email
 * @property string $country
 * @property string $status
 * @property string $identity_tier
 * @property Carbon $created_at
 */
final class Party extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'code', 'kind', 'display_name', 'legal_name', 'primary_phone',
        'primary_email', 'country', 'status', 'identity_tier',
    ];

    protected function casts(): array
    {
        return ['kind' => PartyKind::class];
    }

    /** @return HasMany<PartyUser, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(PartyUser::class);
    }

    /**
     * Suspended and closed parties keep their history and lose their access.
     * Nothing is deleted: their orders and disputes stay attributable.
     */
    public function canAct(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
