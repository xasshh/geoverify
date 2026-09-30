<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Models;

use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Party\Models\PortalAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Somebody paid to have a business checked. See the migration.
 *
 * @property int $id
 * @property string $reference
 * @property int $wallet_id
 * @property int|null $organisation_id
 * @property int|null $enumerate_batch_id
 * @property int $requested_by
 * @property Tier $tier
 * @property int|null $monitoring_days
 * @property Carbon|null $monitoring_starts_on
 * @property Carbon|null $monitoring_ends_on
 * @property int|null $monitoring_officer_id
 * @property int|null $monitoring_missed_days
 * @property int $price_minor
 * @property int $registry_fee_minor
 * @property string $subject_name
 * @property string $rc_number
 * @property string $company_type
 * @property string|null $registered_address
 * @property RequestStatus $status
 * @property Carbon $paid_at
 * @property string|null $registry_outcome
 * @property string|null $registry_reason
 * @property int|null $score
 * @property string|null $finding
 * @property string|null $report_token
 * @property int|null $desk_checked_by
 * @property Carbon|null $desk_checked_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property-read PortalAccount|null $requester
 * @property-read User|null $deskChecker
 * @property-read EnumerateWallet|null $wallet
 * @property-read User|null $monitoringOfficer
 */
final class EnumerateRequest extends Model
{
    protected $fillable = [
        'reference', 'wallet_id', 'organisation_id', 'enumerate_batch_id', 'requested_by', 'tier', 'monitoring_days', 'monitoring_starts_on', 'monitoring_ends_on',
        'monitoring_officer_id', 'monitoring_missed_days', 'price_minor', 'registry_fee_minor',
        'subject_name', 'rc_number', 'company_type', 'registered_address',
        'status', 'paid_at', 'registry_outcome', 'registry_reason', 'score', 'finding', 'report_token',
        'desk_checked_by', 'desk_checked_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'tier' => Tier::class,
            'status' => RequestStatus::class,
            'monitoring_days' => 'integer',
            'monitoring_starts_on' => 'date',
            'monitoring_ends_on' => 'date',
            'monitoring_missed_days' => 'integer',
            'price_minor' => 'integer',
            'registry_fee_minor' => 'integer',
            'score' => 'integer',
            'paid_at' => 'datetime',
            'desk_checked_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PortalAccount, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function deskChecker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'desk_checked_by');
    }

    /** @return BelongsTo<User, $this> */
    public function monitoringOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'monitoring_officer_id');
    }

    /** @return BelongsTo<EnumerateWallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(EnumerateWallet::class, 'wallet_id');
    }

    /** @return HasMany<RegistryCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(RegistryCheck::class);
    }

    /** @return HasMany<EnumerateVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(EnumerateVisit::class);
    }

    /**
     * The newest answer of each kind. An older one stays on the record, but the
     * supervisor reads and the requester sees the latest.
     *
     * @return array<string, RegistryCheck>
     */
    public function latestChecks(): array
    {
        $latest = [];

        foreach ($this->checks()->orderBy('checked_at')->orderBy('id')->get() as $check) {
            $latest[$check->kind] = $check;
        }

        return $latest;
    }
}
