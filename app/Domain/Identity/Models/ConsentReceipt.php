<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What somebody agreed to, in the words they were shown.
 *
 * @property int $id
 * @property string $token
 * @property int $processing_purpose_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property bool $granted
 * @property string $disclosure
 * @property string $disclosure_version
 * @property string $lawful_basis
 * @property string $language
 * @property list<string> $scope
 * @property Carbon $agreed_at
 * @property Carbon|null $withdrawn_at
 * @property int|null $withdrawn_by_receipt_id
 */
final class ConsentReceipt extends Model
{
    protected $fillable = [
        'token', 'processing_purpose_id', 'consent_record_id',
        'subject_type', 'subject_id', 'actor_type', 'actor_id', 'actor_label',
        'granted', 'disclosure', 'disclosure_version', 'lawful_basis',
        'language', 'scope', 'agreed_at', 'withdrawn_at', 'withdrawn_by_receipt_id',
    ];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'scope' => 'array',
            'agreed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProcessingPurpose, $this> */
    public function purpose(): BelongsTo
    {
        return $this->belongsTo(ProcessingPurpose::class, 'processing_purpose_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * A token nobody can guess and nobody can count to.
     *
     * The receipt is handed to a person who may have no account here, so the
     * URL is the whole authorisation. That makes guessability the only thing
     * standing between one person's receipt and everybody's.
     */
    public static function mintToken(): string
    {
        return Str::lower(Str::random(48));
    }

    public function withdrawn(): bool
    {
        return $this->withdrawn_at instanceof Carbon;
    }
}
