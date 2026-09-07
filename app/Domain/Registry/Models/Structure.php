<?php

declare(strict_types=1);

namespace App\Domain\Registry\Models;

use App\Domain\Coverage\Models\AdminBoundary;
use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Coverage\Models\GridCell;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A building, or a thing a business operates out of that is not a building.
 *
 * The columns here are a projection of the latest accepted observation. The
 * observations themselves are the authority.
 *
 * @property int $id
 * @property int $grid_cell_id
 * @property int $coverage_area_id
 * @property int|null $external_footprint_id
 * @property int|null $ward_id
 * @property int|null $lga_id
 * @property int|null $state_id
 * @property int $h3_index
 * @property string|null $plus_code
 * @property int $captured_by
 * @property Carbon $captured_at
 * @property float|null $capture_accuracy_m
 * @property string $structure_type
 * @property string|null $layout_class
 * @property int|null $floors
 * @property int|null $unit_count
 * @property string|null $condition
 * @property string|null $occupancy_status
 * @property int|null $confidence_score
 * @property string $status
 * @property string $client_uuid
 */
final class Structure extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_FLAGGED = 'flagged';

    public const STATUS_REJECTED = 'rejected';

    /**
     * A structure somebody registered themselves, that no officer has stood in
     * front of yet.
     *
     * Deliberately not `submitted`. Submitted means an officer captured it and
     * a supervisor has not looked yet; unconfirmed means nobody has been. They
     * are different claims about the world and a review queue that mixed them
     * would be asking one question about two things.
     */
    public const STATUS_UNCONFIRMED = 'unconfirmed';

    public const ORIGIN_FIELD = 'field';

    public const ORIGIN_SELF_REGISTERED = 'self_registered';

    public function isSelfRegistered(): bool
    {
        return $this->origin === self::ORIGIN_SELF_REGISTERED;
    }

    protected $fillable = [
        'grid_cell_id', 'coverage_area_id', 'external_footprint_id',
        'ward_id', 'lga_id', 'state_id', 'h3_index', 'plus_code',
        'captured_by', 'captured_at', 'capture_accuracy_m',
        'structure_type', 'layout_class', 'floors', 'unit_count',
        'condition', 'occupancy_status', 'confidence_score', 'status', 'client_uuid',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'capture_accuracy_m' => 'float',
            'floors' => 'integer',
            'unit_count' => 'integer',
            'confidence_score' => 'integer',
        ];
    }

    /** @return BelongsTo<GridCell, $this> */
    public function gridCell(): BelongsTo
    {
        return $this->belongsTo(GridCell::class);
    }

    /** @return BelongsTo<CoverageArea, $this> */
    public function coverageArea(): BelongsTo
    {
        return $this->belongsTo(CoverageArea::class);
    }

    /** @return BelongsTo<User, $this> */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    /**
     * The hierarchy this structure resolved into, server side, at ingestion.
     *
     * Read-only relations onto the boundaries ResolveAdminHierarchy already
     * decided. Nothing here recomputes containment: these columns are the
     * answer, and re-deriving them at display time is how a record starts
     * saying two different things about where it is.
     *
     * @return BelongsTo<AdminBoundary, $this>
     */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(AdminBoundary::class, 'ward_id');
    }

    /** @return BelongsTo<AdminBoundary, $this> */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(AdminBoundary::class, 'lga_id');
    }

    /** @return BelongsTo<AdminBoundary, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(AdminBoundary::class, 'state_id');
    }

    /** @return HasMany<StructureObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(StructureObservation::class)->orderByDesc('observed_at');
    }

    /** @return HasMany<Enterprise, $this> */
    public function enterprises(): HasMany
    {
        return $this->hasMany(Enterprise::class);
    }

    /** @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    /**
     * How many of the declared units still have no business recorded against
     * them. This is what turns a half enumerated building into a visible fact
     * rather than something a supervisor has to notice.
     */
    public function unitsOutstanding(): int
    {
        if ($this->unit_count === null) {
            return 0;
        }

        return max(0, $this->unit_count - $this->enterprises()->count());
    }
}
