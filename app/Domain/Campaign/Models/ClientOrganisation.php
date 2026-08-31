<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Models;

use Database\Factories\Domain\Campaign\Models\ClientOrganisationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A body that commissions enumeration.
 *
 * @property int $id
 * @property string $name
 * @property string $short_code
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string $status
 */
final class ClientOrganisation extends Model
{
    /** @use HasFactory<ClientOrganisationFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name', 'short_code', 'contact_name', 'contact_email', 'contact_phone', 'status',
    ];

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /** @return HasMany<ClientUser, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(ClientUser::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
