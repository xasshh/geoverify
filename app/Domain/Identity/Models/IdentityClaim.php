<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * An identity reference attached to an enterprise or a person.
 *
 * The number itself is only ever held long enough to hash it. See
 * HashIdentityReference for why, and for what is kept instead.
 *
 * @property int $id
 * @property string $claimable_type
 * @property int $claimable_id
 * @property string $kind
 * @property string $reference_token
 * @property string|null $reference_last4
 * @property string|null $display_name_returned
 * @property string $status
 * @property string $source
 * @property string|null $verifier
 * @property Carbon|null $verified_at
 * @property string|null $response_ref
 * @property array<string, mixed>|null $raw_payload
 * @property int|null $captured_by
 * @property string $client_uuid
 */
final class IdentityClaim extends Model
{
    /** Public record. Stored in full, because it is meant to be looked up. */
    public const KIND_CAC = 'cac';

    public const KIND_TIN = 'tin';

    /** Never stored. Hashed, last four kept, number discarded. */
    public const KIND_NIN = 'nin';

    /** Never stored, for the same reason. */
    public const KIND_BVN = 'bvn';

    public const KIND_CERTIFICATE = 'cert_of_incorporation';

    public const STATUS_DECLARED = 'declared';

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_MISMATCH = 'mismatch';

    public const STATUS_FAILED = 'failed';

    /**
     * Kinds whose reference must never be stored in readable form.
     *
     * @var list<string>
     */
    public const PERSONAL_KINDS = [self::KIND_NIN, self::KIND_BVN];

    protected $fillable = [
        'claimable_type', 'claimable_id', 'kind', 'reference_token',
        'reference_last4', 'display_name_returned', 'status', 'source',
        'verifier', 'verified_at', 'response_ref', 'raw_payload',
        'captured_by', 'client_uuid',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            // Encrypted at rest. Holds the verifier's receipt, never a credential.
            'raw_payload' => 'encrypted:array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function claimable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    public function isPersonal(): bool
    {
        return in_array($this->kind, self::PERSONAL_KINDS, true);
    }

    /** What a person sees: enough to recognise the credential, and no more. */
    public function maskedReference(): string
    {
        if (! $this->isPersonal()) {
            return $this->reference_token;
        }

        return $this->reference_last4 === null ? 'on file' : '••••'.$this->reference_last4;
    }
}
