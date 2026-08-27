<?php

declare(strict_types=1);

namespace App\Domain\Claim\Models;

use App\Domain\Claim\Enums\ClaimEvidence;
use App\Domain\Claim\Enums\ClaimRelationship;
use App\Domain\Claim\Enums\ClaimStatus;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An assertion of control over a listing, and the evidence offered for it.
 *
 * A claim is an event that happened, not a fact about the present: the answer
 * to "who runs this shop today" lives in `party_businesses`. Reading control
 * off a claim is how you end up with two of them.
 *
 * @property int $id
 * @property int $party_id
 * @property int $enterprise_id
 * @property int $submitted_by
 * @property ClaimRelationship $relationship
 * @property Carbon $asserted_at
 * @property array<string, mixed> $evidence
 * @property ClaimStatus $status
 * @property string|null $decision
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 */
final class Claim extends Model
{
    /** Settled by a code sent to the number the officer captured. */
    public const DECISION_PHONE_MATCH = 'auto_phone_match';

    /** Settled by a CAC roster naming the claimant as a director. */
    public const DECISION_CAC_DIRECTOR = 'auto_cac_director';

    /** Settled by a supervisor looking at it. */
    public const DECISION_REVIEWED = 'reviewed';

    /** Settled by a dispute resolution rather than by this claim's own merits. */
    public const DECISION_DISPUTE = 'dispute_resolution';

    protected $fillable = [
        'party_id', 'enterprise_id', 'submitted_by', 'relationship',
        'asserted_at', 'evidence', 'status', 'decision', 'decided_by',
        'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'relationship' => ClaimRelationship::class,
            'status' => ClaimStatus::class,
            'evidence' => 'array',
            'asserted_at' => 'datetime',
            'decided_at' => 'datetime',
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

    /** @return BelongsTo<PortalAccount, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return HasMany<ClaimPhoneCode, $this> */
    public function phoneCodes(): HasMany
    {
        return $this->hasMany(ClaimPhoneCode::class);
    }

    /**
     * Record what one signal returned, leaving the others untouched.
     *
     * Merged rather than assigned because signals resolve at different moments:
     * proximity is known when the claim is submitted, a phone match minutes
     * later, a roster check when the registry answers. Assigning the whole
     * column at each step is how the earlier ones get lost.
     *
     * @param  array<string, mixed>  $detail
     */
    public function recordEvidence(ClaimEvidence $signal, string $result, array $detail = []): void
    {
        $evidence = $this->evidence;

        $evidence[$signal->value] = [
            'result' => $result,
            'at' => now()->toIso8601String(),
            ...$detail,
        ];

        $this->evidence = $evidence;
    }

    /** Whether a signal was offered and came back confirmed. */
    public function confirms(ClaimEvidence $signal): bool
    {
        $entry = $this->evidence[$signal->value] ?? null;

        return is_array($entry) && ($entry['result'] ?? null) === 'confirmed';
    }
}
