<?php

declare(strict_types=1);

namespace App\Domain\Party\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One sign-in code, hashed, with the attempts made against it.
 *
 * @property int $id
 * @property string $phone
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property int $attempts
 * @property Carbon|null $consumed_at
 * @property string|null $request_ip
 */
final class SignInCode extends Model
{
    /** Five wrong guesses burns this code, not the phone number. */
    public const MAX_ATTEMPTS = 5;

    protected $table = 'portal_sign_in_codes';

    protected $fillable = ['phone', 'code_hash', 'expires_at', 'attempts', 'consumed_at', 'request_ip'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->attempts < self::MAX_ATTEMPTS
            && $this->expires_at->isFuture();
    }
}
