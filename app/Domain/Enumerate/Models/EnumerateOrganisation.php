<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An organisation on Enumerate. See the migration.
 *
 * @property int $id
 * @property string $name
 * @property string|null $rc_number
 * @property string|null $contact_email
 * @property string $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property int|null $account_manager_id
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property-read User|null $accountManager
 */
final class EnumerateOrganisation extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const SUSPENDED = 'suspended';

    protected $fillable = [
        'name', 'rc_number', 'contact_email', 'status', 'decided_by', 'decided_at',
        'decision_note', 'account_manager_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function approved(): bool
    {
        return $this->status === self::APPROVED;
    }

    /** @return BelongsTo<User, $this> */
    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    /** @return HasMany<EnumerateMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(EnumerateMember::class, 'organisation_id');
    }

    /** @return HasMany<EnumerateMember, $this> */
    public function liveMembers(): HasMany
    {
        return $this->members()->whereNull('revoked_at');
    }
}
