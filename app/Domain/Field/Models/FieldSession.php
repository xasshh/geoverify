<?php

declare(strict_types=1);

namespace App\Domain\Field\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One working period on one device.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $assignment_id
 * @property int|null $device_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int $active_seconds
 * @property int $distance_m
 * @property int $fix_count
 * @property string|null $app_version
 * @property string $integrity_verdict
 * @property string $client_uuid
 */
final class FieldSession extends Model
{
    protected $fillable = [
        'user_id', 'assignment_id', 'device_id', 'started_at', 'ended_at',
        'active_seconds', 'distance_m', 'fix_count', 'app_version',
        'integrity_verdict', 'client_uuid',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'active_seconds' => 'integer',
            'distance_m' => 'integer',
            'fix_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return HasMany<PositionFix, $this> */
    public function fixes(): HasMany
    {
        return $this->hasMany(PositionFix::class)->orderBy('recorded_at');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }
}
