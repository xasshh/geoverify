<?php

declare(strict_types=1);

namespace App\Domain\Investment\Models;

use App\Domain\Investment\Enums\InvestorKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property InvestorKind $kind
 * @property string $kyc_status
 * @property Carbon|null $kyc_decided_at
 */
final class InvestorOrganisation extends Model
{
    public const KYC_PENDING = 'pending';

    public const KYC_VERIFIED = 'verified';

    public const KYC_SUSPENDED = 'suspended';

    protected $fillable = [
        'name', 'kind', 'country', 'website', 'kyc_status', 'kyc_decided_by', 'kyc_decided_at', 'kyc_note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => InvestorKind::class,
            'kyc_decided_at' => 'datetime',
        ];
    }

    /** @return HasMany<InvestorUser, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(InvestorUser::class);
    }

    public function isVerified(): bool
    {
        return $this->kyc_status === self::KYC_VERIFIED;
    }

    public function isSuspended(): bool
    {
        return $this->kyc_status === self::KYC_SUSPENDED;
    }
}
