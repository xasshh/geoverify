<?php

declare(strict_types=1);

namespace App\Domain\Registry\Data;

use Illuminate\Support\Carbon;

/**
 * One structure capture, as it arrives from a handset.
 *
 * Deliberately carries no ward, LGA or state. The device is not asked, because
 * the server resolves geography from the point and a field the client could fill
 * in is a field the client could get wrong or lie about.
 */
final readonly class StructureCapture
{
    public function __construct(
        public string $clientUuid,
        public string $observationUuid,
        public int $gridCellId,
        public float $longitude,
        public float $latitude,
        public string $structureType,
        public string $occupancyStatus,
        public Carbon $observedAt,
        public ?float $accuracyM = null,
        public ?int $externalFootprintId = null,
        public ?string $layoutClass = null,
        public ?int $floors = null,
        public ?int $unitCount = null,
        public ?string $condition = null,
        public ?string $notes = null,
        public ?int $fieldSessionId = null,
        public ?int $assignmentId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $float = static fn (string $key): ?float => isset($payload[$key]) && is_numeric($payload[$key])
            ? (float) $payload[$key] : null;
        $int = static fn (string $key): ?int => isset($payload[$key]) && is_numeric($payload[$key])
            ? (int) $payload[$key] : null;
        $str = static fn (string $key): ?string => isset($payload[$key]) && is_scalar($payload[$key])
            ? trim((string) $payload[$key]) : null;

        return new self(
            clientUuid: (string) $payload['client_uuid'],
            observationUuid: (string) $payload['observation_uuid'],
            gridCellId: (int) $payload['grid_cell_id'],
            longitude: (float) $payload['longitude'],
            latitude: (float) $payload['latitude'],
            structureType: (string) $payload['structure_type'],
            occupancyStatus: (string) $payload['occupancy_status'],
            observedAt: Carbon::parse((string) $payload['observed_at']),
            accuracyM: $float('accuracy_m'),
            externalFootprintId: $int('external_footprint_id'),
            layoutClass: $str('layout_class'),
            floors: $int('floors'),
            unitCount: $int('unit_count'),
            condition: $str('condition'),
            notes: $str('notes'),
            fieldSessionId: $int('field_session_id'),
            assignmentId: $int('assignment_id'),
        );
    }
}
