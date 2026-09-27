<?php

declare(strict_types=1);

namespace App\Domain\Investment\Models;

use App\Domain\Investment\Notifications\ResetInvestorPassword;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Somebody signing in for an investor organisation.
 *
 * @property int $id
 * @property int $investor_organisation_id
 * @property string $name
 * @property string $email
 * @property string|null $title
 * @property string $status
 * @property InvestorOrganisation|null $organisation
 */
final class InvestorUser extends Authenticatable
{
    use Notifiable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'investor_users';

    protected $fillable = [
        'investor_organisation_id', 'name', 'email', 'password', 'title', 'status', 'last_signed_in_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_signed_in_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InvestorOrganisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(InvestorOrganisation::class, 'investor_organisation_id');
    }

    /** A person may sign in while their organisation awaits KYC, not once it is suspended. */
    public function canSignIn(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->organisation !== null
            && ! $this->organisation->isSuspended();
    }

    /** @param  string  $token */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetInvestorPassword($token));
    }

    /** Dossiers, data rooms and commissions wait for the organisation to be verified. */
    public function isVerified(): bool
    {
        return $this->organisation?->isVerified() === true;
    }
}
