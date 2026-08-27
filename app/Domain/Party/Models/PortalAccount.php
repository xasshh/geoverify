<?php

declare(strict_types=1);

namespace App\Domain\Party\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * A person who signs in to the portal.
 *
 * Authenticates on its own guard. This is not a `User`: an officer and a shop
 * owner are different kinds of thing, and the field platform reads
 * `Role::supervises()` in enough places that admitting the public to that enum
 * would put a member of it one case away from the supervisor console.
 *
 * @property int $id
 * @property string $name
 * @property string $phone
 * @property Carbon|null $phone_verified_at
 * @property string|null $email
 * @property string|null $password
 * @property string $status
 * @property Carbon|null $last_signed_in_at
 */
final class PortalAccount extends Authenticatable
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'portal_accounts';

    protected $fillable = ['name', 'phone', 'email', 'password', 'status'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'last_signed_in_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<PartyUser, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(PartyUser::class);
    }

    /**
     * The parties this account may currently act for.
     *
     * @return HasMany<PartyUser, $this>
     */
    public function liveMemberships(): HasMany
    {
        return $this->memberships()->whereNull('revoked_at')->whereNotNull('accepted_at');
    }

    public function canSignIn(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
