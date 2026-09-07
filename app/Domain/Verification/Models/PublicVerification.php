<?php

declare(strict_types=1);

namespace App\Domain\Verification\Models;

use App\Domain\Registry\Models\Enterprise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The token a QR code carries, and the answer it resolves to.
 *
 * @property int $id
 * @property string $token
 * @property int $verification_order_id
 * @property int $enterprise_id
 * @property Carbon $issued_at
 * @property Carbon|null $valid_until
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_reason
 */
final class PublicVerification extends Model
{
    protected $fillable = [
        'token', 'verification_order_id', 'enterprise_id',
        'issued_at', 'valid_until', 'revoked_at', 'revocation_reason', 'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'valid_until' => 'date',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<VerificationOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(VerificationOrder::class, 'verification_order_id');
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /**
     * Opaque, and not derived from anything printed beside it.
     *
     * A token computed from the order reference would let anyone holding one
     * certificate enumerate every verification this register has issued.
     */
    public static function mintToken(): string
    {
        return Str::lower(Str::random(40));
    }

    public function revoked(): bool
    {
        return $this->revoked_at instanceof Carbon;
    }

    public function expired(): bool
    {
        return $this->valid_until instanceof Carbon && $this->valid_until->isPast();
    }
}
