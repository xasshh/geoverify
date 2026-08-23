<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Field\Enums\AssignmentStatus;
use App\Domain\Field\Models\Assignment;
use App\Domain\Field\Models\Device;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person who works this system: an officer, a supervisor, or an admin.
 *
 * People are suspended, never deleted. An officer's captures have to stay
 * attributable after they leave, or the evidence loses its author.
 */
/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property Role $role
 * @property string $status
 * @property string|null $staff_ref
 * @property string|null $phone
 * @property Carbon|null $last_active_at
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /** @var list<string> */
    protected $fillable = [
        'name', 'email', 'password', 'role', 'status', 'staff_ref', 'phone',
    ];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'last_active_at' => 'datetime',
        ];
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * Work this officer still holds. Returned work is theirs again.
     *
     * @return HasMany<Assignment, $this>
     */
    public function openAssignments(): HasMany
    {
        return $this->assignments()
            ->whereNull('closed_at')
            ->whereIn('status', [
                AssignmentStatus::Assigned->value,
                AssignmentStatus::InProgress->value,
                AssignmentStatus::Returned->value,
            ]);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function supervises(): bool
    {
        return $this->isActive() && $this->role->supervises();
    }

    public function capturesInTheField(): bool
    {
        return $this->isActive() && $this->role->capturesInTheField();
    }
}
