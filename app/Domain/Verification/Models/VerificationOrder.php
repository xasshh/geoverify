<?php

declare(strict_types=1);

namespace App\Domain\Verification\Models;

use App\Domain\Field\Models\Assignment;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use App\Domain\Verification\Enums\OrderOutcome;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Enums\OrderUrgency;
use App\Domain\Verification\Enums\ServiceZone;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Somebody has paid us to go and look.
 *
 * @property int $id
 * @property string $reference
 * @property int $party_id
 * @property int $enterprise_id
 * @property int $structure_id
 * @property string $tier
 * @property OrderUrgency $urgency
 * @property ServiceZone $zone
 * @property int $amount_minor
 * @property string $currency
 * @property int $sla_working_days
 * @property OrderStatus $status
 * @property OrderOutcome|null $outcome
 * @property Carbon|null $paid_at
 * @property Carbon|null $due_by
 * @property int|null $assignment_id
 * @property Carbon|null $completed_at
 */
final class VerificationOrder extends Model
{
    protected $fillable = [
        'reference', 'party_id', 'enterprise_id', 'structure_id', 'ordered_by',
        'tier', 'urgency', 'zone', 'price_id', 'amount_minor', 'currency',
        'sla_working_days', 'status', 'outcome', 'paid_at', 'due_by',
        'assignment_id', 'completed_by', 'completed_at', 'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'urgency' => OrderUrgency::class,
            'zone' => ServiceZone::class,
            'status' => OrderStatus::class,
            'outcome' => OrderOutcome::class,
            'amount_minor' => 'integer',
            'sla_working_days' => 'integer',
            'paid_at' => 'datetime',
            'due_by' => 'date',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Structure, $this> */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }

    /** @return BelongsTo<PortalAccount, $this> */
    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'ordered_by');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * Orders whose promised date has passed with nobody having attended.
     *
     * The refund is automatic on breach: a customer should never have to ask
     * for it, and one who has to ask is one who writes about it.
     *
     * @param  Builder<VerificationOrder>  $query
     * @return Builder<VerificationOrder>
     */
    public function scopeBreachingSla(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Assigned->value])
            ->whereNotNull('due_by')
            ->whereDate('due_by', '<', now(config('app.timezone'))->toDateString());
    }

    /** The fee, in the units a person reads. */
    public function amount(): float
    {
        return $this->amount_minor / 100;
    }
}
