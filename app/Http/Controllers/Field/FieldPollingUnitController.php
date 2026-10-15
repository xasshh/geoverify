<?php

declare(strict_types=1);

namespace App\Http\Controllers\Field;

use App\Domain\Election\Actions\ManagePollingUnitChecks;
use App\Domain\Election\Models\PollingUnitCheck;
use App\Domain\Field\Actions\ReadOfficerDay;
use App\Domain\Media\Actions\StorePhotograph;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A polling unit check, from the officer's side: where the list puts it, the
 * arrival, photographs by angle, the checklist and the report. A field screen
 * that works offline; the endpoints are what the job outbox sends, each
 * idempotent on the handset's uuid, exactly as a site visit's are.
 */
final class FieldPollingUnitController
{
    public function __construct(private readonly ManagePollingUnitChecks $checks) {}

    public function show(Request $request, PollingUnitCheck $check, ReadOfficerDay $day): Response
    {
        $agent = $this->agent($request, $check);
        $unit = $check->unit()->firstOrFail();

        $point = DB::selectOne('SELECT ST_Y(listed_point::geometry) AS lat, ST_X(listed_point::geometry) AS lng FROM polling_units WHERE id = ? AND listed_point IS NOT NULL', [$unit->id]);

        $returned = PollingUnitCheck::query()
            ->where('polling_unit_id', $unit->id)
            ->where('status', PollingUnitCheck::RETURNED)
            ->whereNotNull('submitted_at')
            ->latest('reviewed_at')
            ->value('review_note');

        return Inertia::render('field/PollingUnit', [
            'day' => $day($agent),
            'check' => [
                'id' => $check->id,
                'status' => $check->status,
                'code' => $unit->inec_code,
                'name' => $unit->name,
                'description' => $unit->description,
                'listedPlace' => $unit->listedPlace(),
                'latitude' => $point === null ? null : round((float) $point->lat, 6),
                'longitude' => $point === null ? null : round((float) $point->lng, 6),
                'redo' => is_string($returned) ? $returned : null,
                'found' => collect(PollingUnitCheck::FOUND)->map(static fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
                'checklist' => collect(PollingUnitCheck::CHECKLIST)->map(static fn (array $c, string $key): array => [
                    'key' => $key,
                    'label' => $c[0],
                    'options' => collect($c[1])->map(static fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
                    'hint' => $c[2],
                ])->values()->all(),
                'arrivedAt' => $check->arrived_at?->toIso8601String(),
                'photos' => [
                    'site' => $check->photos()->where('kind', Media::KIND_PU_SITE)->count(),
                    'entrance' => $check->photos()->where('kind', Media::KIND_PU_ENTRANCE)->count(),
                    'other' => $check->photos()->where('kind', Media::KIND_PU_OTHER)->count(),
                ],
                'submittedAt' => $check->submitted_at?->toIso8601String(),
            ],
        ]);
    }

    public function arrive(Request $request, PollingUnitCheck $check): JsonResponse
    {
        $agent = $this->agent($request, $check);
        $input = $request->validate([
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->answer(fn (): PollingUnitCheck => $this->checks->arrive(
            $check,
            $agent,
            (float) $input['longitude'],
            (float) $input['latitude'],
            isset($input['accuracy_m']) ? (float) $input['accuracy_m'] : null,
        ));
    }

    public function photo(Request $request, PollingUnitCheck $check, StorePhotograph $storer): JsonResponse
    {
        $agent = $this->agent($request, $check);
        $input = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'photo' => ['required', 'file', 'image', 'max:4096'],
            'angle' => ['required', 'in:site,entrance,other'],
            'device_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'device_latitude' => ['nullable', 'numeric', 'between:-90,90'],
        ]);

        try {
            $media = $this->checks->photograph(
                $check,
                $agent,
                $request->file('photo'),
                $input['angle'],
                (string) $input['client_uuid'],
                isset($input['device_longitude']) ? (float) $input['device_longitude'] : null,
                isset($input['device_latitude']) ? (float) $input['device_latitude'] : null,
                $storer,
            );
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['id' => $media->id, 'client_uuid' => $media->client_uuid]);
    }

    public function report(Request $request, PollingUnitCheck $check): JsonResponse
    {
        $agent = $this->agent($request, $check);
        $input = $request->validate([
            'report_uuid' => ['required', 'uuid'],
            'found' => ['required', 'string', 'in:at_listed,elsewhere,not_found'],
            'answers' => ['present', 'array'],
            'answers.*.answer' => ['nullable', 'string', 'max:32'],
            'answers.*.detail' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var array<string, array{answer?: string|null, detail?: string|null}> $answers */
        $answers = $input['answers'];

        return $this->answer(fn (): PollingUnitCheck => $this->checks->submit(
            $check,
            $agent,
            $input['report_uuid'],
            $input['found'],
            $answers,
            $input['notes'] ?? null,
        ));
    }

    /** @param  callable(): PollingUnitCheck  $action */
    private function answer(callable $action): JsonResponse
    {
        try {
            $check = $action();
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['status' => $check->status, 'arrivedAt' => $check->arrived_at?->toIso8601String()]);
    }

    /** A check is shown to the officer it was given to, and is a 404 to everybody else. */
    private function agent(Request $request, PollingUnitCheck $check): User
    {
        $user = $request->user();

        if (! $user instanceof User || $check->agent_id !== $user->id) {
            throw new NotFoundHttpException;
        }

        return $user;
    }
}
