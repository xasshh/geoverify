<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Field\Models\FieldSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * NDPA 2023 consent, recorded where the law requires it: at the point of
 * collection, by the officer who read the script out.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $script_version
 * @property string $script_language
 * @property bool $granted
 * @property string|null $given_by_role
 * @property string $lawful_basis
 * @property string $purpose
 * @property Carbon|null $retain_until
 * @property int $recorded_by
 * @property int|null $field_session_id
 * @property Carbon $recorded_at
 * @property string $client_uuid
 */
final class ConsentRecord extends Model
{
    public const BASIS_CONSENT = 'consent';

    public const BASIS_PUBLIC_INTEREST = 'public_interest';

    public const BASIS_LEGAL_OBLIGATION = 'legal_obligation';

    public const PURPOSE_ENUMERATION = 'business_enumeration';

    public const PURPOSE_IDENTITY = 'identity_verification';

    protected $fillable = [
        'subject_type', 'subject_id', 'script_version', 'script_language',
        'granted', 'given_by_role', 'lawful_basis', 'purpose', 'retain_until',
        'recorded_by', 'field_session_id', 'recorded_at', 'client_uuid',
    ];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'retain_until' => 'date',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<FieldSession, $this> */
    public function fieldSession(): BelongsTo
    {
        return $this->belongsTo(FieldSession::class);
    }
}
