<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Models;

use Database\Factories\Domain\Campaign\Models\ClientUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * A client's administrator. Not staff, and on their own guard.
 *
 * @property int $id
 * @property int $client_organisation_id
 * @property string $name
 * @property string $email
 * @property string $status
 * @property Carbon|null $last_signed_in_at
 * @property-read ClientOrganisation|null $organisation
 */
final class ClientUser extends Authenticatable
{
    /** @use HasFactory<ClientUserFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'client_users';

    /** @var list<string> */
    protected $fillable = [
        'client_organisation_id', 'name', 'email', 'password', 'status', 'last_signed_in_at',
    ];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_signed_in_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ClientOrganisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(ClientOrganisation::class, 'client_organisation_id');
    }

    /**
     * Whether this account may sign in at all.
     *
     * Both halves matter: a live account inside a suspended organisation is a
     * client whose contract has lapsed still reading their campaigns.
     */
    public function canSignIn(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->organisation?->isActive() === true;
    }
}
