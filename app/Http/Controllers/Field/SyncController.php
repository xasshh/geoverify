<?php

declare(strict_types=1);

namespace App\Http\Controllers\Field;

use App\Domain\Sync\Actions\ProcessMutationBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a handset that has been offline tells the server what happened.
 *
 * Returns a result per mutation rather than a single status for the batch. One
 * bad row must not reject the other forty-nine, and the client needs to know
 * which of its queued items it can now forget.
 */
final class SyncController
{
    public function __invoke(Request $request, ProcessMutationBatch $processor): JsonResponse
    {
        $validated = $request->validate([
            // Bounded so a device that has been offline for a week sends several
            // batches rather than one request the server cannot hold in memory.
            'mutations' => ['required', 'array', 'max:200'],
            'mutations.*.client_uuid' => ['required', 'uuid'],
            'mutations.*.entity' => ['required', 'string', 'in:structure,enterprise,area_feature'],
            'mutations.*.op' => ['nullable', 'string', 'in:create,update'],
            'mutations.*.payload' => ['required', 'array'],
            'device_id' => ['nullable', 'string', 'max:128'],
        ]);

        /** @var list<array<string, mixed>> $mutations */
        $mutations = $validated['mutations'];

        $results = $processor->process(
            $mutations,
            $request->user(),
            isset($validated['device_id']) ? (string) $validated['device_id'] : null,
        );

        return new JsonResponse([
            'results' => $results,
            'accepted' => count(array_filter(
                $results,
                static fn (array $r): bool => in_array(
                    $r['status'],
                    [ProcessMutationBatch::STATUS_APPLIED, ProcessMutationBatch::STATUS_DUPLICATE],
                    true,
                ),
            )),
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
