<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Models;

use App\Domain\Campaign\Enums\CampaignStatus;
use App\Domain\Coverage\Models\CoverageArea;
use App\Models\User;
use Database\Factories\Domain\Campaign\Models\CampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A commissioned enumeration exercise.
 *
 * @property int $id
 * @property int $client_organisation_id
 * @property string $code
 * @property string $name
 * @property string $subject_type
 * @property string|null $about
 * @property string|null $objective
 * @property CampaignStatus $status
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property int|null $target_record_count
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $definition_revised_at
 * @property-read ClientOrganisation|null $organisation
 */
final class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    protected $fillable = [
        'client_organisation_id', 'code', 'name', 'subject_type', 'about',
        'objective', 'status', 'starts_on', 'ends_on', 'target_record_count',
        'created_by', 'approved_by', 'approved_at', 'definition_revised_at',
    ];

    /**
     * The commercial relationship is deliberately absent from `$with` and from
     * anything that serialises by default. It is reached explicitly or not at
     * all: see CampaignCommercial for why it is a separate table.
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'approved_at' => 'datetime',
            'definition_revised_at' => 'datetime',
            'target_record_count' => 'integer',
        ];
    }

    /** @return BelongsTo<ClientOrganisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(ClientOrganisation::class, 'client_organisation_id');
    }

    /** @return HasOne<CampaignCommercial, $this> */
    public function commercial(): HasOne
    {
        return $this->hasOne(CampaignCommercial::class);
    }

    /**
     * The ground under this campaign.
     *
     * Mandates, unchanged. Each still owns its grid, its map packs and every
     * structure captured inside it, and the field platform reaches them exactly
     * as it did before campaigns existed.
     *
     * @return HasMany<CoverageArea, $this>
     */
    public function coverageAreas(): HasMany
    {
        return $this->hasMany(CoverageArea::class);
    }

    /** @return HasMany<CampaignField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(CampaignField::class)->orderBy('sort_order');
    }

    /** @return HasMany<CampaignStakeholder, $this> */
    public function stakeholders(): HasMany
    {
        return $this->hasMany(CampaignStakeholder::class);
    }

    /** @return HasMany<CampaignAgentAssignment, $this> */
    public function deployments(): HasMany
    {
        return $this->hasMany(CampaignAgentAssignment::class);
    }

    /** @return HasMany<CampaignAcknowledgement, $this> */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(CampaignAcknowledgement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Everything one organisation may see.
     *
     * Applied at query level rather than by filtering a collection, so a client
     * asking for a campaign id belonging to somebody else finds nothing rather
     * than finding it and being told off. Drafts are excluded here too: a
     * campaign still being written or priced is not the client's to read.
     *
     * @param  Builder<Campaign>  $query
     * @return Builder<Campaign>
     */
    public function scopeVisibleToClient(Builder $query, int $organisationId): Builder
    {
        return $query
            ->where('client_organisation_id', $organisationId)
            ->whereIn('status', array_map(
                static fn (CampaignStatus $status): string => $status->value,
                array_filter(
                    CampaignStatus::cases(),
                    static fn (CampaignStatus $status): bool => $status->visibleToClient(),
                ),
            ));
    }

    /**
     * The exercises one officer is currently out on.
     *
     * @param  Builder<Campaign>  $query
     * @return Builder<Campaign>
     */
    public function scopeDeployedTo(Builder $query, int $userId): Builder
    {
        return $query->whereHas(
            'deployments',
            fn (Builder $deployments) => $deployments
                ->where('user_id', $userId)
                ->whereNull('unassigned_at'),
        );
    }

    /**
     * How far through the calendar this campaign is, as a percentage.
     *
     * Computed off dates rather than stored, and clamped, because a campaign
     * that has run past its end date is at 100 percent rather than at 140.
     */
    public function elapsedPercent(): ?int
    {
        if ($this->starts_on === null || $this->ends_on === null) {
            return null;
        }

        $total = $this->starts_on->diffInDays($this->ends_on);

        if ($total <= 0) {
            return 100;
        }

        $gone = $this->starts_on->diffInDays(Carbon::now(config('app.timezone')), false);

        return (int) max(0, min(100, round(($gone / $total) * 100)));
    }

    /**
     * Whole days left, counted in the application's timezone.
     *
     * Both ends are taken as dates at midnight in the same zone, so this cannot
     * drift by a day depending on what time of day somebody loads the page,
     * which is the usual way this number goes wrong.
     */
    public function daysRemaining(): ?int
    {
        if ($this->ends_on === null) {
            return null;
        }

        $today = Carbon::now(config('app.timezone'))->startOfDay();

        return (int) $today->diffInDays($this->ends_on->copy()->startOfDay(), false);
    }
}
