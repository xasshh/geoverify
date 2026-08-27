<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use App\Domain\Party\Events\SignInCodeIssued;
use App\Domain\Party\Models\SignInCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Sends a one-time code to a phone number.
 *
 * The code is six digits, lives five minutes, and is stored hashed. A readable
 * column holding a live one-time code is a readable column holding a way into
 * somebody's business.
 *
 * The throttle is on **requesting**, not on guessing, and that split is
 * deliberate. Guessing is bounded per code: five wrong tries burns that code
 * and the person asks for another. Requesting is bounded per phone and per
 * address, which is what stops somebody using our gateway to text a stranger
 * repeatedly. Neither locks a legitimate owner out of their own business for a
 * day, which the brief is explicit about and which is the failure mode that
 * costs a real customer.
 */
final class RequestSignInCode
{
    /** Long enough to read from a notification, short enough to be useless later. */
    private const LIFETIME_MINUTES = 5;

    /** Codes per phone per hour, and per address per hour. */
    private const PER_PHONE_HOURLY = 5;

    private const PER_ADDRESS_HOURLY = 20;

    public function __construct(private readonly NormalisePhone $phones) {}

    /**
     * @return array{phone: string, masked: string, expiresInSeconds: int}
     */
    public function __invoke(string $rawPhone, ?string $ip = null): array
    {
        $phone = ($this->phones)($rawPhone);

        $this->throttle("portal-code:phone:{$phone}", self::PER_PHONE_HOURLY,
            'Too many codes requested for that number. Try again in an hour, or sign in on a device you have used before.');

        if ($ip !== null) {
            $this->throttle("portal-code:ip:{$ip}", self::PER_ADDRESS_HOURLY,
                'Too many codes requested from this connection. Try again in an hour.');
        }

        // Any code already outstanding for this number stops working. Two live
        // codes means a person reading the older text signs in with it, which
        // makes "we only ever accept the latest" untrue.
        SignInCode::query()
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        SignInCode::query()->create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
            'request_ip' => $ip,
        ]);

        SignInCodeIssued::dispatch($phone, $code, $this->phones->masked($phone));

        return [
            'phone' => $phone,
            'masked' => $this->phones->masked($phone),
            'expiresInSeconds' => self::LIFETIME_MINUTES * 60,
        ];
    }

    private function throttle(string $key, int $perHour, string $message): void
    {
        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            throw new RuntimeException($message);
        }

        RateLimiter::hit($key, 3_600);
    }
}
