<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Enumerate\Actions\ManageEnumerateVisits;
use App\Domain\Enumerate\Actions\ManageMonitoring;
use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\EnumerateVisit;
use App\Domain\Media\Models\Media;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Enumerate site visits, from the supervisor's side: who needs an officer,
 * who has one, and which reports are waiting to be read.
 *
 * The supervisor sees everything an officer filed, the inside photographs
 * included, because they are what a visit is scored on. What reaches the
 * requester is decided in PresentEnumerateRequest, not here.
 */
final class EnumerateVisitController
{
    public function __construct(
        private readonly ManageEnumerateVisits $visits,
        private readonly ManageMonitoring $monitoring,
    ) {}

    public function index(Request $request): Response
    {
        $waiting = EnumerateRequest::query()
            ->whereIn('status', [RequestStatus::AwaitingAgent->value, RequestStatus::AgentAssigned->value, RequestStatus::OnSite->value, RequestStatus::Monitoring->value])
            ->with([
                'visits' => fn ($q) => $q->whereIn('status', [EnumerateVisit::ASSIGNED, EnumerateVisit::SUBMITTED])->orderBy('id')->with('agent'),
                'monitoringOfficer',
            ])
            ->orderBy('desk_checked_at')
            ->limit(200)
            ->get();

        $reviewing = null;
        $id = $request->query('visit');

        if (is_numeric($id)) {
            $reviewing = EnumerateVisit::query()->with(['request', 'agent', 'ward', 'lga'])->find((int) $id);
        }

        $reviewing ??= EnumerateVisit::query()
            ->where('status', EnumerateVisit::SUBMITTED)
            ->with(['request', 'agent', 'ward', 'lga'])
            ->orderBy('submitted_at')
            ->first();

        return Inertia::render('console/EnumerateVisits', [
            'requests' => $waiting->map(function (EnumerateRequest $r): array {
                // A filed log or report first: it is what is waiting on a person.
                $open = $r->visits->firstWhere('status', EnumerateVisit::SUBMITTED) ?? $r->visits->first();

                return [
                    'reference' => $r->reference,
                    'business' => $r->subject_name,
                    'rcNumber' => $r->rc_number,
                    'tierLabel' => $r->tier->short(),
                    'registeredAddress' => $r->registered_address,
                    'address' => self::cacAddress($r),
                    'waitingSince' => $r->desk_checked_at?->toIso8601String(),
                    'visit' => $open === null ? null : [
                        'id' => $open->id,
                        'status' => $open->status,
                        'agent' => $open->agent === null ? null : ['name' => $open->agent->name, 'ref' => $open->agent->staff_ref],
                        'kind' => $open->kind,
                        'dayNumber' => $open->day_number,
                        'arrivedAt' => $open->arrived_at?->toIso8601String(),
                        'submittedAt' => $open->submitted_at?->toIso8601String(),
                    ],
                    'monitoring' => $r->status === RequestStatus::Monitoring ? $this->monitoringSummary($r) : null,
                ];
            })->values()->all(),
            'reviewing' => $reviewing === null ? null : $this->present($reviewing),
            'officers' => User::query()
                ->where('role', Role::Officer->value)
                ->where('status', User::STATUS_ACTIVE)
                ->orderBy('name')
                ->get(['id', 'name', 'staff_ref'])
                ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'ref' => $u->staff_ref])
                ->all(),
        ]);
    }

    public function assign(Request $request, string $reference): RedirectResponse
    {
        $input = $request->validate([
            'officer_id' => ['required', 'integer'],
            // "9.0421, 7.4912", as a maps app copies a point: latitude first.
            'pin' => ['required', 'string', 'max:60', 'regex:/^\s*-?\d{1,2}(\.\d+)?\s*[,\s]\s*-?\d{1,3}(\.\d+)?\s*$/'],
        ], [
            'pin.regex' => 'Paste the point as latitude, longitude, for example 9.0421, 7.4912.',
        ]);

        [$lat, $lng] = array_map('floatval', preg_split('/[\s,]+/', trim($input['pin'])) ?: []);
        $found = EnumerateRequest::query()->where('reference', $reference)->firstOrFail();
        $agent = User::query()->findOrFail((int) $input['officer_id']);

        try {
            $this->visits->assign($found, $agent, self::supervisor($request), $lat, $lng);
        } catch (RuntimeException $e) {
            return back()->withErrors(['pin' => $e->getMessage()]);
        }

        return back()->with('status', "{$agent->name} has the visit to {$found->subject_name}.");
    }

    /**
     * A filed visit, all of it, for the supervisor reading it.
     *
     * @return array<string, mixed>
     */
    private function present(EnumerateVisit $visit): array
    {
        $point = DB::selectOne(<<<'SQL'
            SELECT ST_Y(site_point::geometry) AS lat, ST_X(site_point::geometry) AS lng,
                   ST_Y(arrival_position::geometry) AS alat, ST_X(arrival_position::geometry) AS alng
              FROM enumerate_visits WHERE id = ?
        SQL, [$visit->id]);

        return [
            'id' => $visit->id,
            'kind' => $visit->kind,
            'dayNumber' => $visit->day_number,
            'log' => $visit->log,
            'status' => $visit->status,
            'reference' => $visit->request?->reference,
            'business' => $visit->request?->subject_name,
            'tierLabel' => $visit->request?->tier->short(),
            'registeredAddress' => $visit->request?->registered_address,
            'agent' => $visit->agent === null ? null : ['name' => $visit->agent->name, 'ref' => $visit->agent->staff_ref],
            'area' => $visit->area(),
            'pin' => sprintf('%.6f, %.6f', (float) $point->lat, (float) $point->lng),
            'arrival' => $point->alat === null ? null : sprintf('%.6f, %.6f', (float) $point->alat, (float) $point->alng),
            'arrivedAt' => $visit->arrived_at?->toIso8601String(),
            'distanceM' => $visit->arrival_distance_m,
            'accuracyM' => $visit->arrival_accuracy_m,
            'submittedAt' => $visit->submitted_at?->toIso8601String(),
            'checklist' => $visit->checklist,
            'notes' => $visit->notes,
            'reviewNote' => $visit->review_note,
            'photos' => $visit->photos()->orderBy('captured_at')->orderBy('id')->get()->map(static fn (Media $m): array => [
                'id' => $m->id,
                'kind' => str_replace('visit_', '', $m->kind),
                'url' => $m->temporaryUrl(15),
                'distanceM' => $m->distance_from_subject_m,
                'fromCamera' => (bool) $m->from_device_camera,
            ])->values()->all(),
        ];
    }

    public function decide(Request $request, EnumerateVisit $visit): RedirectResponse
    {
        $input = $request->validate([
            'accept' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->visits->review($visit, self::supervisor($request), (bool) $input['accept'], $input['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['note' => $e->getMessage()]);
        }

        return redirect()->route('console.enumerate-visits')->with(
            'status',
            (bool) $input['accept'] ? 'Location confirmed. The requester can see the visit now.' : 'Sent back. The officer has a fresh visit with your note.',
        );
    }

    /** Change who does the rest of a Tier 3's daily visits. */
    public function monitoringOfficer(Request $request, string $reference): RedirectResponse
    {
        $input = $request->validate(['officer_id' => ['required', 'integer']]);
        $found = EnumerateRequest::query()->where('reference', $reference)->firstOrFail();

        try {
            $this->monitoring->reassign($found, User::query()->findOrFail((int) $input['officer_id']), self::supervisor($request));
        } catch (RuntimeException $e) {
            return back()->withErrors(['pin' => $e->getMessage()]);
        }

        return back()->with('status', "The daily visits to {$found->subject_name} have a new officer.");
    }

    /** The period is over: earn the price for the days logged, return the rest. */
    public function closeMonitoring(Request $request, string $reference): RedirectResponse
    {
        $found = EnumerateRequest::query()->where('reference', $reference)->firstOrFail();

        try {
            $result = $this->monitoring->close($found, self::supervisor($request));
        } catch (RuntimeException $e) {
            return back()->withErrors(['pin' => $e->getMessage()]);
        }

        return back()->with('status', $result['missed'] === 0
            ? "Monitoring of {$found->subject_name} is closed. Every trading day was logged."
            : sprintf('Monitoring of %s is closed. %d trading %s without a log: ₦%s went back to the wallet.', $found->subject_name, $result['missed'], $result['missed'] === 1 ? 'day' : 'days', number_format($result['returnedMinor'] / 100)));
    }

    /**
     * Where a monitored request stands, for its row.
     *
     * @return array<string, mixed>
     */
    private function monitoringSummary(EnumerateRequest $request): array
    {
        $today = now(config('app.timezone'))->startOfDay();
        $days = EnumerateVisit::query()->where('enumerate_request_id', $request->id)->where('kind', EnumerateVisit::KIND_MONITORING);

        return [
            'startsOn' => $request->monitoring_starts_on?->toDateString(),
            'endsOn' => $request->monitoring_ends_on?->toDateString(),
            'days' => $request->monitoring_days,
            'tradingDays' => count($this->monitoring->tradingDays($request)),
            'logged' => (clone $days)->where('status', EnumerateVisit::ACCEPTED)->count(),
            'missed' => (clone $days)->where('status', EnumerateVisit::MISSED)->count(),
            'officer' => $request->monitoringOfficer === null ? null : ['name' => $request->monitoringOfficer->name, 'ref' => $request->monitoringOfficer->staff_ref],
            'ended' => $request->monitoring_ends_on !== null && $request->monitoring_ends_on->lt($today),
        ];
    }

    /** A maps search for the CAC address, for placing the pin. The requester never sees the pin. */
    private static function cacAddress(EnumerateRequest $request): ?string
    {
        $cac = $request->latestChecks()['cac'] ?? null;
        $address = $cac?->facts['address'] ?? $request->registered_address;

        return is_string($address) && $address !== '' ? $address : null;
    }

    private static function supervisor(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->supervises(), 403);

        return $user;
    }
}
