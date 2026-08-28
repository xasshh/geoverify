<?php

declare(strict_types=1);

namespace App\Domain\Registry\Models;

use App\Domain\Identity\Models\IdentityClaim;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A business operating in a structure.
 *
 * @property int $id
 * @property int $structure_id
 * @property string|null $unit_label
 * @property int $captured_by
 * @property Carbon $captured_at
 * @property string $trading_name
 * @property string|null $registered_name
 * @property string|null $sector_code
 * @property string|null $subsector_code
 * @property string|null $scale_band
 * @property string|null $operating_status
 * @property string $status
 * @property string $client_uuid
 */
final class Enterprise extends Model
{
    public const ORIGIN_FIELD = 'field';

    public const ORIGIN_SELF_REGISTERED = 'self_registered';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    protected $fillable = [
        'structure_id', 'unit_label', 'captured_by', 'captured_at',
        'trading_name', 'registered_name', 'sector_code', 'subsector_code',
        'scale_band', 'operating_status', 'status', 'client_uuid',
        'origin', 'registered_by_party_id',
    ];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime'];
    }

    /** @return BelongsTo<Structure, $this> */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }

    /** @return BelongsTo<User, $this> */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    /** @return HasMany<EnterpriseObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(EnterpriseObservation::class)->orderByDesc('observed_at');
    }

    /** @return MorphMany<IdentityClaim, $this> */
    public function identityClaims(): MorphMany
    {
        return $this->morphMany(IdentityClaim::class, 'claimable');
    }

    /** @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }
}
