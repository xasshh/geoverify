<?php

declare(strict_types=1);

namespace App\Http\Controllers\Field;

use App\Domain\Commerce\Actions\ManageInspections;
use App\Domain\Commerce\Models\Inspection;
use App\Domain\Commerce\Models\PurchaseOrderItem;
use App\Domain\Field\Actions\ReadOfficerDay;
use App\Domain\Media\Actions\StorePhotograph;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * An inspection or site visit, from the agent's side: what to check, arrival,
 * photographs, and the report. The page is a field screen like any other and
 * works offline; the three endpoints are what its outbox sends, each
 * idempotent on the handset's uuid.
 */
final class FieldJobController
{
    public function __construct(private readonly ManageInspections $inspections) {}

    public function show(Request $request, Inspection $inspection, ReadOfficerDay $day): Response
    {
        $agent = $this->agent($request, $inspection);
        $inspection->load(['order.items', 'order.enterprise.structure.ward', 'order.enterprise.structure.lga']);
        $order = $inspection->order;
        $structure = $order?->enterprise->structure;

        return Inertia::render('field/Job', [
            'day' => $day($agent),
            'job' => [
                'id' => $inspection->id,
                'kind' => $inspection->kind,
                'label' => $inspection->label(),
                'status' => $inspection->status,
                'orderRef' => $order?->reference,
                'business' => $order?->enterprise->trading_name,
                'place' => implode(', ', array_filter([$structure?->ward?->name, $structure?->lga?->name])),
                'plusCode' => $structure?->plus_code,
                'requestedFor' => $inspection->requested_for?->toIso8601String(),
                'visitMode' => $inspection->visit_mode,
                // A buyer the agent is meeting is a person they need to reach.
                // Anyone else's number stays with the merchant.
                'buyer' => $inspection->visit_mode === 'with_me' && $order !== null
                    ? ['name' => $order->delivery_name, 'phone' => $order->delivery_phone]
                    : null,
                'items' => $order === null ? [] : $order->items->map(static fn (PurchaseOrderItem $i): array => [
                    'name' => $i->name,
                    'unit' => $i->unit,
                    'quantity' => $i->quantity,
                ])->values()->all(),
                'checklist' => collect(Inspection::CHECKLISTS[$inspection->kind])
                    ->map(static fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
                    ->values()->all(),
                'arrivedAt' => $inspection->arrived_at?->toIso8601String(),
                'photos' => $inspection->photos()->count(),
                'submittedAt' => $inspection->submitted_at?->toIso8601String(),
            ],
        ]);
    }

    public function arrive(Request $request, Inspection $inspection): JsonResponse
    {
        $agent = $this->agent($request, $inspection);
        $input = $request->validate([
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->answer(fn (): Inspection => $this->inspections->arrive(
            $inspection,
            $agent,
            (float) $input['longitude'],
            (float) $input['latitude'],
            isset($input['accuracy_m']) ? (float) $input['accuracy_m'] : null,
        ));
    }

    public function photo(Request $request, Inspection $inspection, StorePhotograph $storer): JsonResponse
    {
        $agent = $this->agent($request, $inspection);
        $input = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'photo' => ['required', 'file', 'image', 'max:4096'],
            'device_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'device_latitude' => ['nullable', 'numeric', 'between:-90,90'],
        ]);

        if ($inspection->submitted_at !== null) {
            return new JsonResponse(['message' => 'The report is already filed.'], 422);
        }

        try {
            $media = $storer->store(
                $request->file('photo'),
                $inspection,
                Media::KIND_INSPECTION,
                $agent,
                (string) $input['client_uuid'],
                isset($input['device_longitude']) ? (float) $input['device_longitude'] : null,
                isset($input['device_latitude']) ? (float) $input['device_latitude'] : null,
            );
        } catch (Throwable $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['id' => $media->id, 'client_uuid' => $media->client_uuid]);
    }

    public function report(Request $request, Inspection $inspection): JsonResponse
    {
        $agent = $this->agent($request, $inspection);
        $input = $request->validate([
            'report_uuid' => ['required', 'uuid'],
            'answers' => ['required', 'array'],
            'answers.*.passed' => ['required', 'boolean'],
            'answers.*.detail' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->answer(fn (): Inspection => $this->inspections->submit(
            $inspection,
            $agent,
            $input['report_uuid'],
            $input['answers'],
            $input['notes'] ?? null,
        ));
    }

    /** @param  callable(): Inspection  $action */
    private function answer(callable $action): JsonResponse
    {
        try {
            $inspection = $action();
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['status' => $inspection->status, 'arrivedAt' => $inspection->arrived_at?->toIso8601String()]);
    }

    /** A job is shown to the agent it was given to, and is a 404 to everybody else. */
    private function agent(Request $request, Inspection $inspection): User
    {
        $user = $request->user();

        if (! $user instanceof User || $inspection->agent_id !== $user->id) {
            throw new NotFoundHttpException;
        }

        return $user;
    }
}
