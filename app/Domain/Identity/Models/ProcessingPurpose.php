<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A reason this system is allowed to hold what it holds.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $description
 * @property string $lawful_basis
 * @property string $disclosure
 * @property string $disclosure_version
 * @property int|null $retention_months
 * @property Carbon|null $retired_at
 */
final class ProcessingPurpose extends Model
{
    /**
     * The four reasons this register processes anything.
     *
     * Constants rather than free strings because a purpose that can be typed
     * is a purpose that can be mistyped, and the whole point of the table is
     * that "on what basis do you hold this" has a closed set of answers.
     */
    public const ENUMERATION = 'field_enumeration';

    public const CLAIM = 'ownership_claim';

    public const VERIFICATION = 'paid_verification';

    public const PUBLICATION = 'public_directory';

    /** NDPA Article 25 grounds, named as the Act names them. */
    public const BASIS_CONSENT = 'consent';

    public const BASIS_CONTRACT = 'contract';

    public const BASIS_LEGAL_OBLIGATION = 'legal_obligation';

    public const BASIS_PUBLIC_INTEREST = 'public_interest';

    public const BASIS_LEGITIMATE_INTEREST = 'legitimate_interest';

    protected $fillable = [
        'key', 'name', 'description', 'lawful_basis',
        'disclosure', 'disclosure_version', 'retention_months', 'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'retention_months' => 'integer',
            'retired_at' => 'datetime',
        ];
    }

    /** @return HasMany<ConsentReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(ConsentReceipt::class);
    }

    /** The purpose a code path names, or a loud failure. */
    public static function named(string $key): self
    {
        return self::query()->where('key', $key)->firstOrFail();
    }
}
