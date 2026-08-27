<?php

declare(strict_types=1);

namespace App\Domain\Claim\Models;

use App\Domain\Registry\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $enterprise_id
 * @property int $incumbent_party_business_id
 * @property int $challenger_claim_id
 * @property Carbon $opened_at
 * @property string|null $grounds
 * @property string|null $resolution
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property string|null $resolution_note
 */
final class ClaimDispute extends Model
{
    public const UPHELD_INCUMBENT = 'upheld_incumbent';

    public const TRANSFERRED = 'transferred_to_challenger';

    public const WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'enterprise_id', 'incumbent_party_business_id', 'challenger_claim_id',
        'opened_at', 'grounds', 'resolution', 'resolved_by', 'resolved_at',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<PartyBusiness, $this> */
    public function incumbent(): BelongsTo
    {
        return $this->belongsTo(PartyBusiness::class, 'incumbent_party_business_id');
    }

    /** @return BelongsTo<Claim, $this> */
    public function challengerClaim(): BelongsTo
    {
        return $this->belongsTo(Claim::class, 'challenger_claim_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->resolution === null;
    }
}
