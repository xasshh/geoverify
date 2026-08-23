<?php

declare(strict_types=1);

namespace App\Domain\Field\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A handset enrolled to one officer.
 *
 * Revoking a device cuts off a lost phone without touching the person's account,
 * and without removing anything it already captured.
 */
/**
 * @property int $id
 * @property int $user_id
 * @property string $device_id
 * @property string|null $model
 * @property string|null $os_version
 * @property string|null $app_version
 * @property string|null $public_key
 * @property bool $gnss_dual_frequency
 * @property string $integrity_verdict
 * @property string $status
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property-read User|null $user
 */
final class Device extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    /** Play Integrity said the handset is genuine and unmodified. */
    public const INTEGRITY_VERIFIED = 'verified';

    /**
     * No attestation was obtained. A progressive web app cannot produce one, so
     * this is the honest default until a signed Android wrapper exists. It is not
     * the same as passing, and the confidence scorer treats it accordingly.
     */
    public const INTEGRITY_UNVERIFIED = 'unverified';

    /** Attestation ran and failed. The strongest signal this system has. */
    public const INTEGRITY_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'device_id', 'model', 'os_version', 'app_version',
        'public_key', 'gnss_dual_frequency', 'integrity_verdict',
        'integrity_checked_at', 'status', 'last_seen_at',
        // Without these two, revoking silently discarded why and when, which is
        // the part of a revocation an auditor actually reads.
        'revoked_at', 'revoked_reason',
    ];

    protected function casts(): array
    {
        return [
            'gnss_dual_frequency' => 'boolean',
            'integrity_checked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
