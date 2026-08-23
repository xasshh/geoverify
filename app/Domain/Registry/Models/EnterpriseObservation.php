<?php

declare(strict_types=1);

namespace App\Domain\Registry\Models;

use App\Domain\Field\Models\FieldSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a business looked like on one visit. Append only.
 *
 * @property int $id
 * @property int $enterprise_id
 * @property int $captured_by
 * @property int|null $field_session_id
 * @property Carbon $observed_at
 * @property string $trading_name
 * @property string|null $registered_name
 * @property string|null $sector_code
 * @property string|null $subsector_code
 * @property string|null $scale_band
 * @property string|null $employee_band
 * @property string $operating_status
 * @property int|null $years_at_location
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $website
 * @property string|null $opening_hours
 * @property bool $signage_observed
 * @property string|null $notes
 * @property string $status
 * @property string $client_uuid
 */
final class EnterpriseObservation extends Model
{
    protected $fillable = [
        'enterprise_id', 'captured_by', 'field_session_id', 'observed_at',
        'trading_name', 'registered_name', 'sector_code', 'subsector_code',
        'scale_band', 'employee_band', 'operating_status', 'years_at_location',
        'phone', 'email', 'website', 'opening_hours', 'signage_observed',
        'notes', 'status', 'client_uuid',
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'signage_observed' => 'boolean',
            'years_at_location' => 'integer',
        ];
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<User, $this> */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    /** @return BelongsTo<FieldSession, $this> */
    public function fieldSession(): BelongsTo
    {
        return $this->belongsTo(FieldSession::class);
    }
}
