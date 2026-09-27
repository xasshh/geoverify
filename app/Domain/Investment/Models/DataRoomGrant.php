<?php

declare(strict_types=1);

namespace App\Domain\Investment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $opportunity_id
 * @property int $investor_organisation_id
 * @property string $status
 * @property string|null $message
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property InvestorOrganisation|null $organisation
 * @property Opportunity|null $opportunity
 */
final class DataRoomGrant extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_GRANTED = 'granted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'opportunity_id', 'investor_organisation_id', 'status', 'requested_by', 'message',
        'decided_by_account_id', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** @return BelongsTo<Opportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /** @return BelongsTo<InvestorOrganisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(InvestorOrganisation::class, 'investor_organisation_id');
    }

    public function isGranted(): bool
    {
        return $this->status === self::STATUS_GRANTED;
    }
}
