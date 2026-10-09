<?php

declare(strict_types=1);

namespace App\Domain\Party\Models;

use App\Domain\Party\Actions\SendPortalPasswordLink;
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
 * @property string|null $phone
 * @property Carbon|null $phone_verified_at
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string|null $google_id
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
            'email_verified_at' => 'datetime',
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

    /**
     * Whether this account has proved it reaches its owner: an email link
     * followed, or (for accounts opened before email) a phone code entered.
     * Nothing behind the portal door opens until it has.
     */
    public function isProved(): bool
    {
        return $this->email_verified_at !== null || $this->phone_verified_at !== null;
    }

    /** Where the password reset link goes, for the portal's password broker. */
    public function getEmailForPasswordReset(): string
    {
        return (string) $this->email;
    }

    /** The reset link is sent by SendPortalPasswordLink, never by Laravel's own notification. */
    public function sendPasswordResetNotification($token): void
    {
        app(SendPortalPasswordLink::class)->deliver($this, (string) $token, 'reset');
    }

    public function canSignIn(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
