<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Field\Models\Assignment;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * An inspection or site visit on one protected order. See the migration.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property string $kind
 * @property string $status
 * @property Carbon|null $requested_for
 * @property string|null $visit_mode
 * @property int|null $assignment_id
 * @property int|null $agent_id
 * @property Carbon|null $assigned_at
 * @property Carbon|null $arrived_at
 * @property float|null $arrival_distance_m
 * @property float|null $arrival_accuracy_m
 * @property string|null $report_uuid
 * @property list<array{key: string, label: string, passed: bool, detail: string|null}>|null $checklist
 * @property string|null $notes
 * @property Carbon|null $submitted_at
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $created_at
 * @property-read PurchaseOrder|null $order
 * @property-read User|null $agent
 * @property-read Assignment|null $assignment
 */
final class Inspection extends Model
{
    public const KIND_INSPECTION = 'inspection';

    public const KIND_SITE_VISIT = 'site_visit';

    public const REQUESTED = 'requested';

    public const ASSIGNED = 'assigned';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Metres from the premises within which an arrival counts as at the premises. */
    public const ARRIVAL_TOLERANCE_M = 50;

    /**
     * What the agent answers, per kind. Fixed, so every report on every order
     * says the same things and a buyer can compare two of them.
     */
    public const CHECKLISTS = [
        self::KIND_INSPECTION => [
            'quantity' => 'Quantity matches order',
            'weight' => 'Weight or size within tolerance',
            'packaging' => 'Packaging sealed and undamaged',
            'matches_listing' => 'Goods match the listing',
        ],
        self::KIND_SITE_VISIT => [
            'premises' => 'Premises open and trading at this address',
            'signage' => 'Name on the signage matches the listing',
            'stock' => 'Goods ordered are in stock',
            'attended' => 'Buyer attended, or was kept informed',
        ],
    ];

    protected $fillable = [
        'purchase_order_id', 'kind', 'status', 'requested_for', 'visit_mode', 'assignment_id', 'agent_id',
        'assigned_at', 'arrived_at', 'arrival_distance_m', 'arrival_accuracy_m', 'report_uuid', 'checklist',
        'notes', 'submitted_at', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'requested_for' => 'datetime',
            'assigned_at' => 'datetime',
            'arrived_at' => 'datetime',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'arrival_distance_m' => 'float',
            'arrival_accuracy_m' => 'float',
            'checklist' => 'array',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * The agent's photographs of this job. Officer authored, kind inspection,
     * attached here and nowhere else.
     *
     * @return MorphMany<Media, $this>
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')
            ->where('kind', Media::KIND_INSPECTION)
            ->whereNotNull('captured_by')
            ->orderBy('id');
    }

    public function label(): string
    {
        return $this->kind === self::KIND_SITE_VISIT ? 'Site visit' : 'Product inspection';
    }
}
