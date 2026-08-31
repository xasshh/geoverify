<?php

declare(strict_types=1);

namespace App\Domain\Registry\Models;

use App\Domain\Media\Models\Media;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Enums\CorrectableField;
use App\Domain\Registry\Enums\CorrectionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A party's account of something the register has wrong.
 *
 * @property int $id
 * @property int $party_id
 * @property int $enterprise_id
 * @property int $proposed_by
 * @property CorrectableField $field
 * @property string|null $current_value
 * @property string|null $proposed_value
 * @property string $reason
 * @property int|null $evidence_media_id
 * @property CorrectionStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $decision_note
 */
final class CorrectionProposal extends Model
{
    protected $fillable = [
        'party_id', 'enterprise_id', 'proposed_by', 'field', 'current_value',
        'proposed_value', 'reason', 'evidence_media_id', 'status',
        'reviewed_by', 'reviewed_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'field' => CorrectableField::class,
            'status' => CorrectionStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<PortalAccount, $this> */
    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'proposed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<Media, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'evidence_media_id');
    }

    /**
     * Waiting on somebody.
     *
     * @param  Builder<CorrectionProposal>  $query
     * @return Builder<CorrectionProposal>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', CorrectionStatus::Submitted->value);
    }
}
