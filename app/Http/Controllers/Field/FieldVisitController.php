<?php

declare(strict_types=1);

namespace App\Http\Controllers\Field;

use App\Domain\Enumerate\Actions\ManageEnumerateVisits;
use App\Domain\Enumerate\Actions\ManageMonitoring;
use App\Domain\Enumerate\Models\EnumerateVisit;
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
 * An Enumerate site visit, from the officer's side: where to go, arrival,
 * photographs by angle, and the report. A field screen like any other, that
 * works offline; the endpoints are what the job outbox sends, each idempotent
 * on the handset's uuid, exactly as an inspection's are.
 */
final class FieldVisitController
{
    public function __construct(private readonly ManageEnumerateVisits $visits) {}

    public function show(Request $request, EnumerateVisit $visit, ReadOfficerDay $day): Response
    {
        $agent = $this->agent($request, $visit);
        $visit->load(['request', 'ward', 'lga']);
        $subject = $visit->request;

        // The pin, for the officer's own navigation. Staff see where to go;
        // the requester never does.
        $point = DB::selectOne('SELECT ST_Y(site_point::geometry) AS lat, ST_X(site_point::geometry) AS lng FROM enumerate_visits WHERE id = ?', [$visit->id]);

        $returned = EnumerateVisit::query()
            ->where('enumerate_request_id', $visit->enumerate_request_id)
            ->where('status', EnumerateVisit::RETURNED)
            ->whereNotNull('submitted_at')
            ->latest('reviewed_at')
            ->value('review_note');

        return Inertia::render('field/Visit', [
            'day' => $day($agent),
            'visit' => [
                'id' => $visit->id,
                'kind' => $visit->kind,
                'dayNumber' => $visit->day_number,
                'days' => $subject?->monitoring_days,
                'status' => $visit->status,
                'reference' => $subject?->reference,
                'business' => $subject?->subject_name,
                'registration' => $subject === null ? null : $subject->rc_number,
                'registeredAddress' => $subject?->registered_address,
                'area' => $visit->area(),
                'latitude' => round((float) $point->lat, 6),
                'longitude' => round((float) $point->lng, 6),
                'redo' => is_string($returned) ? $returned : null,
                'checklist' => collect(EnumerateVisit::CHECKLIST)
                    ->map(static fn (array $c, string $key): array => ['key' => $key, 'label' => $c[0], 'hint' => $c[1]])
                    ->values()->all(),
                'arrivedAt' => $visit->arrived_at?->toIso8601String(),
                'photos' => [
                    'storefront' => $visit->photos()->where('kind', Media::KIND_VISIT_STOREFRONT)->count(),
                    'signage' => $visit->photos()->where('kind', Media::KIND_VISIT_SIGNAGE)->count(),
                    'interior' => $visit->photos()->where('kind', Media::KIND_VISIT_INTERIOR)->count(),
                    'other' => $visit->photos()->where('kind', Media::KIND_VISIT_OTHER)->count(),
                ],
                'submittedAt' => $visit->submitted_at?->toIso8601String(),
            ],
        ]);
    }

    public function arrive(Request $request, EnumerateVisit $visit): JsonResponse
    {
        $agent = $this->agent($request, $visit);
        $input = $request->validate([
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->answer(fn (): EnumerateVisit => $this->visits->arrive(
            $visit,
            $agent,
            (float) $input['longitude'],
            (float) $input['latitude'],
            isset($input['accuracy_m']) ? (float) $input['accuracy_m'] : null,
        ));
    }

    public function photo(Request $request, EnumerateVisit $visit, StorePhotograph $storer): JsonResponse
    {
        $agent = $this->agent($request, $visit);
        $input = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'photo' => ['required', 'file', 'image', 'max:4096'],
            'angle' => ['required', 'in:storefront,signage,interior,other'],
            'device_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'device_latitude' => ['nullable', 'numeric', 'between:-90,90'],
        ]);

        try {
            $media = $this->visits->photograph(
                $visit,
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

    public function report(Request $request, EnumerateVisit $visit, ManageMonitoring $monitoring): JsonResponse
    {
        $agent = $this->agent($request, $visit);

        if ($visit->kind === EnumerateVisit::KIND_MONITORING) {
            $log = $request->validate([
                'report_uuid' => ['required', 'uuid'],
                'log' => ['required', 'array'],
                'log.state' => ['required', 'in:open,low,closed'],
                'log.opens' => ['nullable', 'string', 'max:5'],
                'log.closes' => ['nullable', 'string', 'max:5'],
                'log.staff' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'log.customers' => ['nullable', 'integer', 'min:0', 'max:99999'],
                'log.activity' => ['required', 'string', 'max:300'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);

            return $this->answer(fn (): EnumerateVisit => $monitoring->submitLog(
                $visit,
                $agent,
                $log['report_uuid'],
                $log['log'],
                $log['notes'] ?? null,
            ));
        }

        $input = $request->validate([
            'report_uuid' => ['required', 'uuid'],
            'answers' => ['required', 'array'],
            'answers.*.passed' => ['required', 'boolean'],
            'answers.*.detail' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->answer(fn (): EnumerateVisit => $this->visits->submit(
            $visit,
            $agent,
            $input['report_uuid'],
            $input['answers'],
            $input['notes'] ?? null,
        ));
    }

    /** @param  callable(): EnumerateVisit  $action */
    private function answer(callable $action): JsonResponse
    {
        try {
            $visit = $action();
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['status' => $visit->status, 'arrivedAt' => $visit->arrived_at?->toIso8601String()]);
    }

    /** A visit is shown to the officer it was given to, and is a 404 to everybody else. */
    private function agent(Request $request, EnumerateVisit $visit): User
    {
        $user = $request->user();

        if (! $user instanceof User || $visit->agent_id !== $user->id) {
            throw new NotFoundHttpException;
        }

        return $user;
    }
}
