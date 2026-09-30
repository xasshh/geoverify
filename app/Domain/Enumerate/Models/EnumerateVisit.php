<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Models;

use App\Domain\Coverage\Models\AdminBoundary;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * An officer's visit to a business somebody paid to have checked. See the migration.
 *
 * @property int $id
 * @property int $enumerate_request_id
 * @property string $kind
 * @property int|null $day_number
 * @property Carbon|null $visit_date
 * @property string $status
 * @property int $agent_id
 * @property int $assigned_by
 * @property Carbon $assigned_at
 * @property int|null $ward_id
 * @property int|null $lga_id
 * @property Carbon|null $arrived_at
 * @property float|null $arrival_distance_m
 * @property float|null $arrival_accuracy_m
 * @property string|null $report_uuid
 * @property list<array{key: string, label: string, passed: bool, detail: string|null}>|null $checklist
 * @property array{state: string, opens: string|null, closes: string|null, staff: int|null, customers: int|null, activity: string}|null $log
 * @property string|null $notes
 * @property Carbon|null $submitted_at
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property-read EnumerateRequest|null $request
 * @property-read User|null $agent
 * @property-read User|null $reviewer
 * @property-read AdminBoundary|null $ward
 * @property-read AdminBoundary|null $lga
 */
final class EnumerateVisit extends Model
{
    public const ASSIGNED = 'assigned';

    public const SUBMITTED = 'submitted';

    public const ACCEPTED = 'accepted';

    public const RETURNED = 'returned';

    /** A day's visit nobody filed on the day. Kept, so the log can say so. */
    public const MISSED = 'missed';

    public const KIND_SITE = 'site';

    public const KIND_MONITORING = 'monitoring';

    /** How the business was found on a daily visit. */
    public const DAY_STATES = ['open', 'low', 'closed'];

    /**
     * What the officer answers at the premises, and the example that tells
     * them what kind of detail is wanted. Yes or no, and a line of detail.
     */
    public const CHECKLIST = [
        'premises_found' => ['Premises found at the registered address', 'e.g. at the gate of Plot 7, or where it really is'],
        'signage_matches' => ['Business name on the signage matches', 'The name exactly as the sign reads'],
        'open_during_visit' => ['Business was open during the visit', 'e.g. open, 6 staff seen'],
        'premises_as_described' => ['Premises are what the business says it is', 'e.g. warehouse with a showroom'],
        'no_concerns' => ['Nothing of concern at the premises', 'e.g. a second entrance, unmarked'],
    ];

    /** Metres from the pin within which the officer counts as at the registered address. */
    public const AT_ADDRESS_M = 100;

    protected $fillable = [
        'enumerate_request_id', 'kind', 'day_number', 'visit_date', 'log', 'status', 'agent_id', 'assigned_by', 'assigned_at',
        'ward_id', 'lga_id', 'arrived_at', 'arrival_distance_m', 'arrival_accuracy_m',
        'report_uuid', 'checklist', 'notes', 'submitted_at',
        'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'arrived_at' => 'datetime',
            'arrival_distance_m' => 'float',
            'arrival_accuracy_m' => 'float',
            'checklist' => 'array',
            'log' => 'array',
            'visit_date' => 'date',
            'day_number' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnumerateRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(EnumerateRequest::class, 'enumerate_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<AdminBoundary, $this> */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(AdminBoundary::class, 'ward_id');
    }

    /** @return BelongsTo<AdminBoundary, $this> */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(AdminBoundary::class, 'lga_id');
    }

    /**
     * Every photograph of this visit, for staff.
     *
     * @return MorphMany<Media, $this>
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->whereIn('kind', Media::VISIT_KINDS);
    }

    /**
     * The photographs the requester may see: the outside, asked for by name.
     * Never "everything but the interior" (see CLAUDE.md).
     *
     * @return MorphMany<Media, $this>
     */
    public function exteriorPhotos(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')
            ->whereIn('kind', [Media::KIND_VISIT_STOREFRONT, Media::KIND_VISIT_SIGNAGE])
            ->where('status', Media::STATUS_STORED);
    }

    /** "Ward, LGA", from the boundaries, for a report. */
    public function area(): ?string
    {
        $parts = array_filter([$this->ward?->name, $this->lga?->name]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}
