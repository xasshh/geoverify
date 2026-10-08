<?php

declare(strict_types=1);

namespace App\Domain\Sync\Actions;

use App\Domain\AreaCapture\Actions\CaptureAreaFeature;
use App\Domain\Registry\Actions\CaptureEnterprise;
use App\Domain\Registry\Actions\CaptureStructure;
use App\Domain\Registry\Data\StructureCapture;
use App\Domain\Registry\Models\Structure;
use App\Domain\Sync\Exceptions\DeferredMutation;
use App\Domain\Sync\Models\SyncReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Applies a batch of mutations from a handset that has been offline.
 *
 * Three properties matter, and the third is the one that is usually missing:
 *
 *  1. Idempotent. A device retrying three times produces one record and gets the
 *     same answer all three times, because it cannot tell a lost response from
 *     lost work.
 *
 *  2. Ordered. Mutations are applied in the order the officer made them, which
 *     is what UUID v7 gives without inventing a sequence number. A business
 *     cannot be attached to a building the server has not seen yet.
 *
 *  3. Partial failure tolerant. One bad mutation must not reject the other
 *     forty-nine. Each is applied in its own transaction and reported on its
 *     own, so a day's work is never held hostage by a single row.
 *
 * A mutation whose parent has not arrived is deferred rather than failed: on a
 * bad connection the batch containing the parent may simply be later in the
 * queue, and telling the officer their shop is invalid would be wrong.
 */
final class ProcessMutationBatch
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED = 'rejected';

    public function __construct(
        private readonly CaptureStructure $structures,
        private readonly CaptureEnterprise $enterprises,
        private readonly CaptureAreaFeature $areaFeatures,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $mutations
     * @return list<array<string, mixed>>
     */
    public function process(array $mutations, User $officer, ?string $deviceId = null): array
    {
        // Creation order, which UUID v7 encodes in its own prefix. A client that
        // sends them shuffled still gets them applied in the order they happened.
        usort(
            $mutations,
            static fn (array $a, array $b): int => strcmp(
                (string) ($a['client_uuid'] ?? ''),
                (string) ($b['client_uuid'] ?? ''),
            ),
        );

        $results = [];

        foreach ($mutations as $mutation) {
            $results[] = $this->applyOne($mutation, $officer, $deviceId);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $mutation
     * @return array<string, mixed>
     */
    private function applyOne(array $mutation, User $officer, ?string $deviceId): array
    {
        $clientUuid = (string) ($mutation['client_uuid'] ?? '');
        $entity = (string) ($mutation['entity'] ?? '');
        $operation = (string) ($mutation['op'] ?? 'create');
        /** @var array<string, mixed> $payload */
        $payload = is_array($mutation['payload'] ?? null) ? $mutation['payload'] : [];

        if ($clientUuid === '' || $entity === '') {
            return $this->result($clientUuid, self::STATUS_REJECTED, 'A mutation needs a client_uuid and an entity.');
        }

        $hash = $this->hash($payload);

        // Answered from the receipt without re-running the work. The device
        // cannot tell a lost response from lost work, so it retries, and this is
        // what makes that safe.
        $seen = SyncReceipt::query()
            ->where('client_uuid', $clientUuid)
            ->where('payload_hash', $hash)
            ->first();

        if ($seen instanceof SyncReceipt) {
            return $this->result(
                $clientUuid,
                self::STATUS_DUPLICATE,
                null,
                $seen->resulting_id,
                $seen->resulting_type,
            );
        }

        try {
            $record = DB::transaction(fn (): Model => $this->dispatch($entity, $payload, $officer));
        } catch (DeferredMutation $e) {
            // The parent is probably in a later batch. Not the officer's problem
            // and not an error, so no receipt is written and the client keeps it
            // queued.
            return $this->result($clientUuid, self::STATUS_DEFERRED, $e->getMessage());
        } catch (Throwable $e) {
            SyncReceipt::query()->create([
                'client_uuid' => $clientUuid,
                'payload_hash' => $hash,
                'user_id' => $officer->id,
                'device_id' => $deviceId,
                'entity' => $entity,
                'operation' => $operation,
                'status' => self::STATUS_FAILED,
                'error' => $e->getMessage(),
                'received_at' => now(),
            ]);

            // Recorded as failed so the client stops retrying something that will
            // never succeed, and a supervisor can see what was lost and why.
            return $this->result($clientUuid, self::STATUS_FAILED, $e->getMessage());
        }

        SyncReceipt::query()->create([
            'client_uuid' => $clientUuid,
            'payload_hash' => $hash,
            'user_id' => $officer->id,
            'device_id' => $deviceId,
            'entity' => $entity,
            'operation' => $operation,
            'resulting_type' => $record->getMorphClass(),
            'resulting_id' => $record->getKey(),
            'status' => self::STATUS_APPLIED,
            'received_at' => now(),
        ]);

        return $this->result(
            $clientUuid,
            self::STATUS_APPLIED,
            null,
            $record->getKey(),
            $record->getMorphClass(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(string $entity, array $payload, User $officer): Model
    {
        return match ($entity) {
            'structure' => $this->structures->capture(StructureCapture::fromArray($payload), $officer),
            'enterprise' => $this->enterprises->capture($this->withResolvedParent($payload), $officer),
            // Area capture (land, water, the things on it). Added beside the
            // two above and touching neither: a handset that never sends it
            // syncs exactly as before.
            'area_feature' => ($this->areaFeatures)($payload, $officer),
            default => throw new RuntimeException("This system does not sync '{$entity}' records."),
        };
    }

    /**
     * Resolves a business's parent building by the client's own uuid.
     *
     * A handset that has been offline knows its structure only by the uuid it
     * generated, never by a server id it has not been told yet. Requiring one
     * would mean a business could not be captured until its building had synced,
     * which defeats the point of working offline.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withResolvedParent(array $payload): array
    {
        if (isset($payload['structure_id'])) {
            return $payload;
        }

        $parentUuid = (string) ($payload['structure_client_uuid'] ?? '');

        if ($parentUuid === '') {
            throw new RuntimeException('A business needs the building it is in.');
        }

        $structure = Structure::query()->where('client_uuid', $parentUuid)->first();

        if (! $structure instanceof Structure) {
            throw new DeferredMutation(
                'The building this business is in has not arrived yet. Holding it for the next sync.',
            );
        }

        $payload['structure_id'] = $structure->id;

        return $payload;
    }

    /**
     * A stable digest of the payload.
     *
     * Keys are sorted, because two clients serialising the same record in a
     * different key order have not made two different changes.
     *
     * @param  array<string, mixed>  $payload
     */
    private function hash(array $payload): string
    {
        $normalise = static function (mixed $value) use (&$normalise): mixed {
            if (! is_array($value)) {
                return $value;
            }

            ksort($value);

            return array_map($normalise, $value);
        };

        /** @var array<string, mixed> $sorted */
        $sorted = $normalise($payload);

        return hash('sha256', json_encode($sorted, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function result(
        string $clientUuid,
        string $status,
        ?string $message = null,
        int|string|null $id = null,
        ?string $type = null,
    ): array {
        return array_filter([
            'client_uuid' => $clientUuid,
            'status' => $status,
            'message' => $message,
            'id' => $id,
            'type' => $type,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
