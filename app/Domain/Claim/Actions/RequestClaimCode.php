<?php

declare(strict_types=1);

namespace App\Domain\Claim\Actions;

use App\Domain\Claim\Events\ClaimCodeIssued;
use App\Domain\Claim\Models\Claim;
use App\Domain\Claim\Models\ClaimPhoneCode;
use App\Domain\Party\Actions\NormalisePhone;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Sends a code to the number recorded at the business.
 *
 * Throttled harder than sign-in, and throttled on a different axis. Sign-in
 * limits per phone, because the thing being protected is one account. This
 * limits per claiming party as well, because the thing being protected is every
 * business in the register: a party that opens claims on forty listings and
 * requests a code for each is not a customer having trouble, and the per-phone
 * limit alone would never notice, since each of those forty is a different
 * number being texted once.
 */
final class RequestClaimCode
{
    private const LIFETIME_MINUTES = 10;

    /** Codes for one claim, ever. Three failed sends is a wrong number. */
    private const PER_CLAIM = 3;

    /** Claim codes one party may send in an hour, across all its claims. */
    private const PER_PARTY_HOURLY = 10;

    public function __construct(
        private readonly RecordedPhone $recorded,
        private readonly NormalisePhone $phones,
    ) {}

    /**
     * @return array{masked: string, expiresInSeconds: int}
     */
    public function __invoke(Claim $claim, ?string $ip = null): array
    {
        $phone = $this->recorded->forClaim($claim);

        if ($phone === null) {
            throw new RuntimeException('No phone number was recorded for this business, so we cannot send a code.');
        }

        $sent = ClaimPhoneCode::query()->where('claim_id', $claim->id)->count();

        if ($sent >= self::PER_CLAIM) {
            throw new RuntimeException('We have sent as many codes as we can for this claim. Choose another way to prove control.');
        }

        $key = "claim-code:party:{$claim->party_id}";

        if (RateLimiter::tooManyAttempts($key, self::PER_PARTY_HOURLY)) {
            throw new RuntimeException('Too many claim codes requested. Try again in an hour.');
        }

        RateLimiter::hit($key, 3_600);

        ClaimPhoneCode::query()
            ->where('claim_id', $claim->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        ClaimPhoneCode::query()->create([
            'claim_id' => $claim->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
            'request_ip' => $ip,
        ]);

        ClaimCodeIssued::dispatch(
            $phone,
            $code,
            $this->phones->masked($phone),
            $claim->enterprise->trading_name,
            $claim->id,
        );

        VerificationEvent::recordForParty($claim->enterprise, 'claim.code_sent', $claim->party, [
            'claim_id' => $claim->id,
            'attempt' => $sent + 1,
        ]);

        return [
            'masked' => $this->phones->masked($phone),
            'expiresInSeconds' => self::LIFETIME_MINUTES * 60,
        ];
    }
}
