<?php

declare(strict_types=1);

namespace App\Domain\Claim\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $claim_id
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property int $attempts
 * @property Carbon|null $consumed_at
 * @property string|null $request_ip
 */
final class ClaimPhoneCode extends Model
{
    /**
     * Five guesses at a six-digit code, then the code dies.
     *
     * Not a rate limit: a limit slows an attacker down, and this ends the
     * attempt. The distinction matters because the prize here is control of
     * somebody's business listing rather than one session.
     */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = ['claim_id', 'code_hash', 'expires_at', 'attempts', 'consumed_at', 'request_ip'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    /** @return BelongsTo<Claim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function isLive(): bool
    {
        return $this->consumed_at === null
            && $this->attempts < self::MAX_ATTEMPTS
            && $this->expires_at->isFuture();
    }
}
