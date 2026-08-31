<?php

declare(strict_types=1);

namespace App\Http\Controllers\Field;

use App\Domain\Field\Actions\RecordTrace;
use App\Domain\Field\Models\FieldSession;
use App\Domain\Media\Actions\StorePhotograph;
use App\Domain\Media\Models\Media;
use App\Domain\Registry\Actions\CaptureEnterprise;
use App\Domain\Registry\Actions\CaptureStructure;
use App\Domain\Registry\Actions\ResolveAdminHierarchy;
use App\Domain\Registry\Actions\SearchSectors;
use App\Domain\Registry\Data\StructureCapture;
use App\Domain\Registry\Models\Structure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * What the field client posts to while it has signal.
 *
 * Every endpoint here is idempotent on a client generated uuid, because the same
 * submission arriving three times is the normal case on a bad connection, not an
 * error. The offline queue at M5 relies on that already being true.
 */
final class CaptureController
{
    public function startSession(Request $request, RecordTrace $tracer): JsonResponse
    {
        $validated = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'assignment_id' => ['nullable', 'integer', 'exists:assignments,id'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'integrity_verdict' => ['nullable', 'string', 'in:verified,unverified,failed'],
        ]);

        try {
            $session = $tracer->startSession(
                $request->user(),
                $validated['client_uuid'],
                isset($validated['assignment_id']) ? (int) $validated['assignment_id'] : null,
                appVersion: $validated['app_version'] ?? null,
                integrityVerdict: $validated['integrity_verdict'] ?? 'unverified',
            );
        } catch (Throwable $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'id' => $session->id,
            'client_uuid' => $session->client_uuid,
            'started_at' => $session->started_at->toIso8601String(),
        ], $session->wasRecentlyCreated ? 201 : 200);
    }

    public function appendFixes(Request $request, FieldSession $session, RecordTrace $tracer): JsonResponse
    {
        $this->assertOwnSession($request, $session);

        $validated = $request->validate([
            'fixes' => ['required', 'array', 'max:1000'],
            'fixes.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'fixes.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'fixes.*.recorded_at' => ['required', 'date'],
            'fixes.*.accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'fixes.*.satellite_count' => ['nullable', 'integer', 'min:0', 'max:99'],
            'fixes.*.is_mock' => ['nullable', 'boolean'],
            'fixes.*.provider' => ['nullable', 'string', 'in:gps,network,fused'],
        ]);

        /** @var list<array<string, mixed>> $fixes */
        $fixes = $validated['fixes'];
        $result = $tracer->appendFixes($session, $fixes);
        $session->refresh();

        return new JsonResponse([
            'stored' => $result['stored'],
            'duplicates' => $result['duplicates'],
            'fix_count' => $session->fix_count,
            'distance_m' => $session->distance_m,
            'active_seconds' => $session->active_seconds,
        ]);
    }

    public function endSession(Request $request, FieldSession $session, RecordTrace $tracer): JsonResponse
    {
        $this->assertOwnSession($request, $session);

        $ended = $tracer->endSession($session, $request->user());

        return new JsonResponse([
            'ended_at' => $ended->ended_at?->toIso8601String(),
            'fix_count' => $ended->fix_count,
            'distance_m' => $ended->distance_m,
            'active_seconds' => $ended->active_seconds,
        ]);
    }

    public function storeStructure(
        Request $request,
        CaptureStructure $capturer,
        ResolveAdminHierarchy $hierarchy,
    ): JsonResponse {
        $validated = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'observation_uuid' => ['required', 'uuid'],
            'grid_cell_id' => ['required', 'integer', 'exists:grid_cells,id'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'external_footprint_id' => ['nullable', 'integer', 'exists:external_footprints,id'],
            'structure_type' => ['required', 'string'],
            'occupancy_status' => ['required', 'string'],
            'layout_class' => ['nullable', 'string', 'max:32'],
            'floors' => ['nullable', 'integer', 'min:0', 'max:200'],
            'unit_count' => ['nullable', 'integer', 'min:0', 'max:500'],
            'condition' => ['nullable', 'string', 'max:24'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'observed_at' => ['required', 'date'],
            'field_session_id' => ['nullable', 'integer', 'exists:field_sessions,id'],
            'assignment_id' => ['nullable', 'integer', 'exists:assignments,id'],
        ]);

        try {
            // Cell ownership is enforced inside the action, so the live post and
            // the offline sync path cannot drift apart on who is allowed to
            // capture where.
            $structure = $capturer->capture(StructureCapture::fromArray($validated), $request->user());
        } catch (Throwable $e) {
            return new JsonResponse(
                ['message' => $e->getMessage()],
                str_contains($e->getMessage(), 'not assigned to you') ? 403 : 422,
            );
        }

        return new JsonResponse([
            'id' => $structure->id,
            'client_uuid' => $structure->client_uuid,
            'status' => $structure->status,
            // Echoed back so the officer can see where the server placed the
            // capture, which is the only ward that counts.
            'resolved' => $hierarchy->describe([
                'ward_id' => $structure->ward_id,
                'lga_id' => $structure->lga_id,
                'state_id' => $structure->state_id,
            ]),
            'units_outstanding' => $structure->unitsOutstanding(),
        ], 201);
    }

    public function storeEnterprise(Request $request, CaptureEnterprise $capturer): JsonResponse
    {
        $validated = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'observation_uuid' => ['required', 'uuid'],
            'structure_id' => ['required', 'integer', 'exists:structures,id'],
            'unit_label' => ['nullable', 'string', 'max:32'],
            // Ground is 0, a basement is negative. Bounded rather than checked
            // against the building's own storey count: an officer who recorded
            // two floors and then found a business on the third has miscounted
            // the building, not the business, and the field client is the one
            // place this system never blocks. Review sees the contradiction.
            'floor' => ['nullable', 'integer', 'min:-5', 'max:200'],
            'trading_name' => ['required', 'string', 'max:255'],
            'registered_name' => ['nullable', 'string', 'max:255'],
            'sector_code' => ['nullable', 'string', 'exists:isic_classes,code'],
            'scale_band' => ['nullable', 'string', 'in:micro,small,medium,large'],
            'employee_band' => ['nullable', 'string', 'max:16'],
            'operating_status' => ['nullable', 'string', 'max:24'],
            'years_at_location' => ['nullable', 'integer', 'min:0', 'max:200'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'signage_observed' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'observed_at' => ['required', 'date'],
            'field_session_id' => ['nullable', 'integer', 'exists:field_sessions,id'],
        ]);

        $structure = Structure::query()->findOrFail((int) $validated['structure_id']);
        Gate::authorize('capture', $structure);

        try {
            $enterprise = $capturer->capture($validated, $request->user());
        } catch (Throwable $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'id' => $enterprise->id,
            'client_uuid' => $enterprise->client_uuid,
            'trading_name' => $enterprise->trading_name,
            'sector_code' => $enterprise->sector_code,
            'units_outstanding' => $structure->fresh()?->unitsOutstanding() ?? 0,
        ], 201);
    }

    /**
     * A photograph of a structure.
     *
     * Uploaded separately from the record it belongs to, and always after it, so
     * a 12 MB photograph on a 2G connection can never block a capture from being
     * saved. At M5 this becomes a resumable chunked upload drained from the
     * queue; the separation is what makes that possible without rework.
     */
    public function storePhotograph(Request $request, StorePhotograph $storer): JsonResponse
    {
        $validated = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'structure_id' => ['required', 'integer', 'exists:structures,id'],
            'kind' => ['required', 'string', 'in:facade,signage,interior,document,street_context'],
            'photo' => ['required', 'file', 'image', 'max:4096'],
            'device_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'device_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'field_session_id' => ['nullable', 'integer', 'exists:field_sessions,id'],
        ]);

        $structure = Structure::query()->findOrFail((int) $validated['structure_id']);
        Gate::authorize('capture', $structure);

        try {
            $media = $storer->store(
                $request->file('photo'),
                $structure,
                (string) $validated['kind'],
                $request->user(),
                (string) $validated['client_uuid'],
                isset($validated['device_longitude']) ? (float) $validated['device_longitude'] : null,
                isset($validated['device_latitude']) ? (float) $validated['device_latitude'] : null,
                isset($validated['field_session_id']) ? (int) $validated['field_session_id'] : null,
            );
        } catch (Throwable $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'id' => $media->id,
            'client_uuid' => $media->client_uuid,
            'kind' => $media->kind,
            'bytes' => $media->bytes,
            // Surfaced so the officer sees what the record will show a supervisor,
            // rather than discovering it in a review three weeks later.
            'distance_from_subject_m' => $media->distance_from_subject_m,
            'from_device_camera' => $media->from_device_camera,
        ], 201);
    }

    /**
     * A short lived link to a stored photograph.
     *
     * The only way a photograph leaves this system. Ten minutes, because a link
     * that outlives the screen it was made for is a link that gets forwarded.
     */
    public function showPhotograph(Request $request, Media $media): JsonResponse
    {
        Gate::authorize('view', $media);

        return new JsonResponse(['url' => $media->temporaryUrl()]);
    }

    /** The sector picker. Called on every keystroke, so it stays cheap. */
    public function searchSectors(Request $request, SearchSectors $sectors): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:64'],
        ]);

        return new JsonResponse(['results' => $sectors->search($validated['q'])]);
    }

    private function assertOwnSession(Request $request, FieldSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, 403, 'That is not your session.');
    }
}
