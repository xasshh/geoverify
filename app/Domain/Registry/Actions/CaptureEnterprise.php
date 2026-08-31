<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\EnterpriseObservation;
use App\Domain\Registry\Models\Structure;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records a business, or a new observation of one already known.
 *
 * Same disciplines as a structure: append an observation, project it forward,
 * never overwrite the last visit, and stay idempotent on client_uuid.
 */
final class CaptureEnterprise
{
    public function __construct(
        private readonly SearchSectors $sectors,
        private readonly DetectDuplicateEnterprise $duplicates,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function capture(array $payload, User $officer): Enterprise
    {
        if (! $officer->capturesInTheField()) {
            throw new RuntimeException('Only an active field officer can capture a business.');
        }

        return DB::transaction(function () use ($payload, $officer): Enterprise {
            $clientUuid = (string) $payload['client_uuid'];

            $existing = Enterprise::query()
                ->where('client_uuid', $clientUuid)
                ->lockForUpdate()
                ->first();

            return $existing instanceof Enterprise
                ? $this->addObservation($existing, $payload, $officer)
                : $this->createEnterprise($payload, $officer);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createEnterprise(array $payload, User $officer): Enterprise
    {
        $structure = Structure::query()->findOrFail((int) $payload['structure_id']);
        $tradingName = trim((string) $payload['trading_name']);
        $sectorCode = isset($payload['sector_code']) ? (string) $payload['sector_code'] : null;

        $enterprise = Enterprise::query()->create([
            'structure_id' => $structure->id,
            'unit_label' => isset($payload['unit_label']) ? (string) $payload['unit_label'] : null,
            'floor' => self::floorIn($payload),
            'captured_by' => $officer->id,
            'captured_at' => Carbon::parse((string) $payload['observed_at']),
            'trading_name' => $tradingName,
            'registered_name' => isset($payload['registered_name']) ? (string) $payload['registered_name'] : null,
            'sector_code' => $sectorCode,
            'subsector_code' => $sectorCode === null ? null : $this->sectors->divisionFor($sectorCode),
            'scale_band' => isset($payload['scale_band']) ? (string) $payload['scale_band'] : null,
            'operating_status' => (string) ($payload['operating_status'] ?? 'operating'),
            'status' => Enterprise::STATUS_SUBMITTED,
            'client_uuid' => (string) $payload['client_uuid'],
        ]);

        $observation = $this->writeObservation($enterprise, $payload, $officer);

        // Two records metres apart with near identical names are one business
        // counted twice, which inflates a register a client is paying for.
        $duplicates = $this->duplicates->near($enterprise);

        VerificationEvent::record($enterprise, 'enterprise.captured', $officer, [
            'client_uuid' => $enterprise->client_uuid,
            'structure_id' => $structure->id,
            'sector_code' => $sectorCode,
            'observation_id' => $observation->id,
            'possible_duplicates' => $duplicates,
        ]);

        return $enterprise->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function addObservation(Enterprise $enterprise, array $payload, User $officer): Enterprise
    {
        $observationUuid = (string) $payload['observation_uuid'];

        if (EnterpriseObservation::query()->where('client_uuid', $observationUuid)->exists()) {
            return $enterprise;
        }

        $before = $enterprise->only(['trading_name', 'sector_code', 'scale_band', 'operating_status']);
        $observation = $this->writeObservation($enterprise, $payload, $officer);
        $sectorCode = isset($payload['sector_code']) ? (string) $payload['sector_code'] : null;

        $enterprise->update([
            'trading_name' => trim((string) $payload['trading_name']),
            'registered_name' => isset($payload['registered_name']) ? (string) $payload['registered_name'] : null,
            'sector_code' => $sectorCode,
            'subsector_code' => $sectorCode === null ? null : $this->sectors->divisionFor($sectorCode),
            'scale_band' => isset($payload['scale_band']) ? (string) $payload['scale_band'] : null,
            'operating_status' => (string) ($payload['operating_status'] ?? 'operating'),
            'status' => Enterprise::STATUS_SUBMITTED,

            // Only when the handset actually said something. A client that does
            // not send a floor has not told us the business moved to the ground
            // one, and overwriting a known placement with null on every revisit
            // would quietly empty the column as the older clients sync.
            ...(self::floorIn($payload) === null ? [] : ['floor' => self::floorIn($payload)]),
        ]);

        VerificationEvent::record($enterprise, 'enterprise.re_observed', $officer, [
            'observation_id' => $observation->id,
            'previous' => $before,
            'current' => $enterprise->only(['trading_name', 'sector_code', 'scale_band', 'operating_status']),
        ]);

        return $enterprise->refresh();
    }

    /**
     * The storey, if the officer recorded one.
     *
     * Ground is 0, so a falsy check here would read the ground floor as "not
     * answered" and throw away the most common answer in the register.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function floorIn(array $payload): ?int
    {
        return isset($payload['floor']) && is_numeric($payload['floor'])
            ? (int) $payload['floor']
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeObservation(Enterprise $enterprise, array $payload, User $officer): EnterpriseObservation
    {
        $str = static fn (string $k): ?string => isset($payload[$k]) && is_scalar($payload[$k])
            ? trim((string) $payload[$k]) : null;
        $int = static fn (string $k): ?int => isset($payload[$k]) && is_numeric($payload[$k])
            ? (int) $payload[$k] : null;

        return EnterpriseObservation::query()->create([
            'enterprise_id' => $enterprise->id,
            'captured_by' => $officer->id,
            'field_session_id' => $int('field_session_id'),
            'observed_at' => Carbon::parse((string) $payload['observed_at']),
            'trading_name' => trim((string) $payload['trading_name']),
            'registered_name' => $str('registered_name'),
            'sector_code' => $str('sector_code'),
            'subsector_code' => $str('sector_code') === null
                ? null
                : $this->sectors->divisionFor((string) $str('sector_code')),
            'scale_band' => $str('scale_band'),
            'employee_band' => $str('employee_band'),
            'operating_status' => (string) ($payload['operating_status'] ?? 'operating'),
            'years_at_location' => $int('years_at_location'),
            'phone' => $str('phone'),
            'email' => $str('email'),
            'website' => $str('website'),
            'opening_hours' => $str('opening_hours'),
            'signage_observed' => (bool) ($payload['signage_observed'] ?? false),
            'notes' => $str('notes'),
            'client_uuid' => (string) $payload['observation_uuid'],
        ]);
    }
}
