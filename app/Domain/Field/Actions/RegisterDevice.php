<?php

declare(strict_types=1);

namespace App\Domain\Field\Actions;

use App\Domain\Field\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;

/**
 * Enrols a handset to an officer and issues its token.
 *
 * One token per device, not per person. A lost phone is revoked on its own, and
 * the officer keeps working on another handset without any change to their
 * account.
 */
final class RegisterDevice
{
    /** The only ability a field client needs. Scoped, so a leaked token cannot review. */
    public const ABILITY_FIELD = 'field:capture';

    /**
     * @return array{device: Device, token: NewAccessToken}
     */
    public function register(
        User $officer,
        string $deviceId,
        ?string $model = null,
        ?string $osVersion = null,
        ?string $appVersion = null,
        ?string $publicKey = null,
        bool $dualFrequencyGnss = false,
    ): array {
        if (! $officer->capturesInTheField()) {
            throw new RuntimeException('Only an active field officer can enrol a device.');
        }

        return DB::transaction(function () use (
            $officer, $deviceId, $model, $osVersion, $appVersion, $publicKey, $dualFrequencyGnss
        ): array {
            /** @var Device|null $existing */
            $existing = Device::query()->where('device_id', $deviceId)->lockForUpdate()->first();

            if ($existing instanceof Device && $existing->user_id !== $officer->id) {
                // The same handset turning up under a second officer is either a
                // shared phone or a cloned identifier. Both need a person to look,
                // so it fails loudly rather than silently re-enrolling.
                throw new RuntimeException(
                    'That device is already enrolled to another officer. Revoke it first.',
                );
            }

            $device = Device::query()->updateOrCreate(
                ['device_id' => $deviceId],
                [
                    'user_id' => $officer->id,
                    'model' => $model,
                    'os_version' => $osVersion,
                    'app_version' => $appVersion,
                    'public_key' => $publicKey,
                    'gnss_dual_frequency' => $dualFrequencyGnss,
                    // Set explicitly rather than left to the column default, so the
                    // object in hand and the row on disk say the same thing.
                    'integrity_verdict' => Device::INTEGRITY_UNVERIFIED,
                    'status' => Device::STATUS_ACTIVE,
                    'last_seen_at' => now(),
                ],
            );

            // Replaced rather than added to: re-enrolling a handset should not leave
            // an older token valid somewhere.
            $officer->tokens()->where('name', $this->tokenName($device))->delete();

            $token = $officer->createToken($this->tokenName($device), [self::ABILITY_FIELD]);

            return ['device' => $device, 'token' => $token];
        });
    }

    public function revoke(Device $device, User $revokedBy, string $reason): Device
    {
        if (! $revokedBy->supervises()) {
            throw new RuntimeException('Only a supervisor can revoke a device.');
        }

        return DB::transaction(function () use ($device, $reason): Device {
            $device->user?->tokens()->where('name', $this->tokenName($device))->delete();

            $device->update([
                'status' => Device::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ]);

            return $device->fresh() ?? $device;
        });
    }

    private function tokenName(Device $device): string
    {
        return "device:{$device->device_id}";
    }
}
