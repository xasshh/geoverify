<?php

declare(strict_types=1);

namespace App\Domain\Election\Models;

use App\Domain\Campaign\Models\Campaign;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * One officer's visit to one polling unit. Written once; a return closes it
 * and opens the next (ManagePollingUnitChecks).
 *
 * @property int $id
 * @property int $polling_unit_id
 * @property int $campaign_id
 * @property string $status
 * @property int $agent_id
 * @property int $assigned_by
 * @property Carbon $assigned_at
 * @property Carbon|null $arrived_at
 * @property float|null $arrival_accuracy_m
 * @property float|null $arrival_distance_m
 * @property string|null $report_uuid
 * @property string|null $found
 * @property array<string, array{answer: string, detail: string|null}>|null $answers
 * @property string|null $notes
 * @property Carbon|null $submitted_at
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 */
final class PollingUnitCheck extends Model
{
    public const ASSIGNED = 'assigned';

    public const SUBMITTED = 'submitted';

    public const ACCEPTED = 'accepted';

    public const RETURNED = 'returned';

    /** Was the unit where the list puts it. */
    public const FOUND = [
        'at_listed' => 'Yes, where the list says',
        'elsewhere' => 'Yes, but somewhere else',
        'not_found' => 'Could not find it',
    ];

    /** Metres from the listed point within which a unit counts as where the list says. */
    public const AT_LISTED_M = 150;

    /**
     * What the officer records at each unit: a question, its answers, and the
     * example that says what kind of detail is wanted. The order is the order
     * on the screen and in every export.
     *
     * @var array<string, array{0: string, 1: array<string, string>, 2: string}>
     */
    public const CHECKLIST = [
        'venue' => ['What kind of place is it?', [
            'school' => 'School',
            'public_building' => 'Other public building',
            'open_space' => 'Open space or field',
            'market' => 'Market',
            'religious' => 'Church or mosque',
            'private' => 'Private premises',
            'other' => 'Something else',
        ], 'e.g. LEA Primary School, the main block'],
        'step_free' => ['Can a wheelchair user get in and vote?', [
            'yes' => 'Yes, level or ramped',
            'partly' => 'With help (a step or rough ground)',
            'no' => 'No',
        ], 'e.g. two steps at the gate, no ramp'],
        'shelter' => ['Is there shade or a roof for voters?', ['yes' => 'Yes', 'no' => 'No'], 'e.g. covered veranda for about 50 people'],
        'queue_space' => ['Is there room for a long queue?', ['yes' => 'Yes', 'no' => 'No'], 'e.g. the yard holds about 200 people'],
        'toilet_water' => ['Toilet or drinking water nearby?', ['yes' => 'Yes', 'no' => 'No'], 'e.g. borehole at the back'],
        'lighting' => ['Is there lighting if counting runs late?', ['yes' => 'Yes', 'no' => 'No'], 'e.g. one security light at the gate'],
        'signal' => ['Phone signal at the unit', ['good' => 'Good', 'weak' => 'Weak', 'none' => 'None'], 'e.g. MTN good, Airtel weak'],
        'vehicle_access' => ['Can a vehicle reach it?', ['yes' => 'Yes', 'foot' => 'Only on foot'], 'e.g. untarred road, passable when dry'],
        'flood_risk' => ['Does it flood, or look like it could?', ['no' => 'No', 'yes' => 'Yes'], 'e.g. low ground beside a drainage channel'],
        'security' => ['Any security concern?', ['no' => 'No', 'yes' => 'Yes'], 'Say what, without naming people'],
    ];

    /** A "no" (or worse) on these is a finding the client wants counted. */
    public const CONCERNS = [
        'step_free' => ['no', 'partly'],
        'shelter' => ['no'],
        'queue_space' => ['no'],
        'toilet_water' => ['no'],
        'lighting' => ['no'],
        'signal' => ['none'],
        'vehicle_access' => ['foot'],
        'flood_risk' => ['yes'],
        'security' => ['yes'],
    ];

    protected $fillable = [
        'polling_unit_id', 'campaign_id', 'status', 'agent_id', 'assigned_by', 'assigned_at',
        'arrived_at', 'arrival_accuracy_m', 'arrival_distance_m', 'report_uuid', 'found', 'answers', 'notes',
        'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'arrived_at' => 'datetime',
            'arrival_accuracy_m' => 'float',
            'arrival_distance_m' => 'float',
            'answers' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PollingUnit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class, 'polling_unit_id');
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
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

    /**
     * Every photograph of this check, for staff.
     *
     * @return MorphMany<Media, $this>
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->whereIn('kind', Media::PU_KINDS);
    }

    /**
     * What the client may see: the site and the entrance, asked for by name.
     *
     * @return MorphMany<Media, $this>
     */
    public function clientPhotos(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')
            ->whereIn('kind', [Media::KIND_PU_SITE, Media::KIND_PU_ENTRANCE])
            ->where('status', Media::STATUS_STORED);
    }
}
