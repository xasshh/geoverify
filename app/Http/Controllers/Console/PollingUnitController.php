<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Campaign\Enums\CampaignStatus;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Election\Actions\ExportPollingUnits;
use App\Domain\Election\Actions\ImportPollingUnits;
use App\Domain\Election\Actions\ManagePollingUnitChecks;
use App\Domain\Election\Actions\SummarisePollingUnits;
use App\Domain\Election\Models\PollingUnit;
use App\Domain\Election\Models\PollingUnitCheck;
use App\Domain\Media\Models\Media;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Polling unit checks, from the supervisor's side: load the list, send
 * officers ward by ward, and read what they filed.
 *
 * A supervisor sees everything an officer filed, the "other" photographs
 * included. What reaches the client is decided in SummarisePollingUnits,
 * ExportPollingUnits and the client's own controller, none of which names an
 * officer.
 */
final class PollingUnitController
{
    private const PER_PAGE = 100;

    public function __construct(private readonly ManagePollingUnitChecks $checks) {}

    public function index(Request $request, SummarisePollingUnits $summarise): Response
    {
        $campaigns = Campaign::query()
            ->whereJsonContains('capture_modes', 'polling_units')
            ->where('status', '!=', CampaignStatus::Archived->value)
            ->orderByDesc('id')
            ->get(['id', 'code', 'name', 'status']);

        $campaign = $campaigns->firstWhere('id', (int) $request->query('campaign')) ?? $campaigns->first();

        if (! $campaign instanceof Campaign) {
            return Inertia::render('console/PollingUnits', ['campaigns' => [], 'campaign' => null]);
        }

        $filters = [
            'lga' => is_string($request->query('lga')) ? $request->query('lga') : '',
            'ward' => is_string($request->query('ward')) ? $request->query('ward') : '',
            'status' => in_array($request->query('status'), PollingUnit::STATUSES, true) ? (string) $request->query('status') : '',
            'q' => is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 80) : '',
        ];

        $page = max(1, (int) $request->query('page', '1'));
        $query = $this->filtered($campaign, $filters);
        $total = (clone $query)->count();

        $units = $query
            ->with(['checks' => fn ($q) => $q->whereIn('status', [PollingUnitCheck::ASSIGNED, PollingUnitCheck::SUBMITTED])->with('agent:id,name,staff_ref')])
            ->orderBy('listed_lga')->orderBy('listed_ward')->orderBy('inec_code')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $reviewing = null;
        $id = $request->query('check');

        if (is_numeric($id)) {
            $reviewing = PollingUnitCheck::query()->where('campaign_id', $campaign->id)->find((int) $id);
        }

        $reviewing ??= PollingUnitCheck::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', PollingUnitCheck::SUBMITTED)
            ->orderBy('submitted_at')
            ->first();

        return Inertia::render('console/PollingUnits', [
            'campaigns' => $campaigns->map(static fn (Campaign $c): array => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->values()->all(),
            'campaign' => ['id' => $campaign->id, 'code' => $campaign->code, 'name' => $campaign->name],
            'summary' => $summarise($campaign),
            'waiting' => PollingUnitCheck::query()->where('campaign_id', $campaign->id)->where('status', PollingUnitCheck::SUBMITTED)->count(),
            'filters' => $filters,
            'lgas' => DB::table('polling_units')->where('campaign_id', $campaign->id)->whereNotNull('listed_lga')->distinct()->orderBy('listed_lga')->pluck('listed_lga')->all(),
            'wards' => $filters['lga'] === '' ? [] : DB::table('polling_units')->where('campaign_id', $campaign->id)->where('listed_lga', $filters['lga'])->whereNotNull('listed_ward')->distinct()->orderBy('listed_ward')->pluck('listed_ward')->all(),
            'units' => [
                'total' => $total,
                'page' => $page,
                'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
                'rows' => $units->map(static function (PollingUnit $u): array {
                    $open = $u->checks->first();

                    return [
                        'id' => $u->id,
                        'code' => $u->inec_code,
                        'name' => $u->name,
                        'place' => $u->listedPlace(),
                        'status' => $u->status,
                        'distanceM' => $u->listed_distance_m,
                        'officer' => $open?->agent === null ? null : ['name' => $open->agent->name, 'ref' => $open->agent->staff_ref],
                        'openCheck' => $open === null ? null : ['id' => $open->id, 'status' => $open->status, 'arrived' => $open->arrived_at !== null],
                    ];
                })->values()->all(),
            ],
            'officers' => User::query()->where('role', Role::Officer->value)->where('status', User::STATUS_ACTIVE)->orderBy('name')
                ->get(['id', 'name', 'staff_ref'])
                ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'ref' => $u->staff_ref])->values()->all(),
            'reviewing' => $reviewing === null ? null : $this->present($reviewing),
        ]);
    }

    public function import(Request $request, Campaign $campaign, ImportPollingUnits $import): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt'],
        ], ['file.mimes' => 'Send the list as a CSV file. In Excel: File, Save As, CSV.']);

        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        try {
            $result = $import($campaign, $this->supervisor($request), (string) $file->getRealPath());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $status = sprintf('%s new %s, %s updated.', number_format($result['created']), $result['created'] === 1 ? 'unit' : 'units', number_format($result['updated']));
        if ($result['errors'] !== []) {
            $status .= ' '.count($result['errors']).' '.(count($result['errors']) === 1 ? 'line needs' : 'lines need').' a look: '.implode(' ', array_slice($result['errors'], 0, 5));
        }

        return redirect()->route('console.polling-units', ['campaign' => $campaign->id])->with('status', $status);
    }

    /** Units picked by hand, or every unit matching the filters on screen that is not yet with anyone. */
    public function assign(Request $request, Campaign $campaign): RedirectResponse
    {
        $data = $request->validate([
            'officer_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'unit_ids' => ['array'],
            'unit_ids.*' => ['integer'],
            'matching' => ['boolean'],
            'lga' => ['nullable', 'string', 'max:120'],
            'ward' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        $ids = ($data['matching'] ?? false)
            ? $this->filtered($campaign, [
                'lga' => (string) ($data['lga'] ?? ''),
                'ward' => (string) ($data['ward'] ?? ''),
                'status' => in_array($data['status'] ?? '', PollingUnit::STATUSES, true) ? (string) $data['status'] : '',
                'q' => (string) ($data['q'] ?? ''),
            ])->limit(2000)->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : array_values(array_map('intval', $data['unit_ids'] ?? []));

        try {
            $result = $this->checks->assign($campaign->id, $ids, User::query()->findOrFail((int) $data['officer_id']), $this->supervisor($request));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['assign' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            '%d %s sent.%s',
            $result['assigned'],
            $result['assigned'] === 1 ? 'unit' : 'units',
            $result['skipped'] > 0 ? " {$result['skipped']} already had an officer and were left alone." : '',
        ));
    }

    public function withdraw(Request $request, PollingUnitCheck $check): RedirectResponse
    {
        try {
            $this->checks->withdraw($check, $this->supervisor($request));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['assign' => $e->getMessage()]);
        }

        return back()->with('status', 'Withdrawn. The unit is free to send again.');
    }

    public function review(Request $request, PollingUnitCheck $check): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'return'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->checks->review($check, $this->supervisor($request), $data['decision'] === 'accept', $data['note'] ?? null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['review' => $e->getMessage()]);
        }

        return redirect()->route('console.polling-units', ['campaign' => $check->campaign_id])
            ->with('status', $data['decision'] === 'accept' ? 'Accepted. The unit is settled.' : 'Sent back to the officer with your note.');
    }

    public function export(Request $request, Campaign $campaign, ExportPollingUnits $export): StreamedResponse
    {
        $format = $request->query('format');
        abort_unless(is_string($format) && in_array($format, ExportPollingUnits::FORMATS, true), 404);

        return $export($campaign, $format);
    }

    /**
     * @param  array{lga: string, ward: string, status: string, q: string}  $filters
     * @return Builder<PollingUnit>
     */
    private function filtered(Campaign $campaign, array $filters): Builder
    {
        return PollingUnit::query()
            ->where('campaign_id', $campaign->id)
            ->when($filters['lga'] !== '', fn ($q) => $q->where('listed_lga', $filters['lga']))
            ->when($filters['ward'] !== '', fn ($q) => $q->where('listed_ward', $filters['ward']))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('inec_code', 'ilike', '%'.addcslashes($filters['q'], '%_\\').'%')
                ->orWhere('name', 'ilike', '%'.addcslashes($filters['q'], '%_\\').'%')));
    }

    /** @return array<string, mixed> */
    private function present(PollingUnitCheck $check): array
    {
        $unit = $check->unit()->with(['lga', 'ward'])->firstOrFail();
        $point = DB::selectOne(<<<'SQL'
            SELECT ST_Y(c.arrival_position::geometry) AS lat, ST_X(c.arrival_position::geometry) AS lng,
                   ST_Y(u.listed_point::geometry) AS listed_lat, ST_X(u.listed_point::geometry) AS listed_lng
              FROM polling_unit_checks c JOIN polling_units u ON u.id = c.polling_unit_id
             WHERE c.id = ?
        SQL, [$check->id]);

        $answers = $check->answers ?? [];

        return [
            'id' => $check->id,
            'status' => $check->status,
            'code' => $unit->inec_code,
            'name' => $unit->name,
            'listedPlace' => $unit->listedPlace(),
            'agent' => $check->agent()->first(['id', 'name', 'staff_ref'])?->only(['name', 'staff_ref']),
            'arrivedAt' => $check->arrived_at?->toIso8601String(),
            'submittedAt' => $check->submitted_at?->toIso8601String(),
            'accuracyM' => $check->arrival_accuracy_m,
            'distanceM' => $check->arrival_distance_m,
            'position' => $point?->lat === null ? null : ['lat' => round((float) $point->lat, 6), 'lng' => round((float) $point->lng, 6)],
            'listed' => $point?->listed_lat === null ? null : ['lat' => round((float) $point->listed_lat, 6), 'lng' => round((float) $point->listed_lng, 6)],
            'found' => $check->found === null ? null : PollingUnitCheck::FOUND[$check->found] ?? $check->found,
            'foundKey' => $check->found,
            'answers' => collect(PollingUnitCheck::CHECKLIST)->map(static fn (array $c, string $key): array => [
                'key' => $key,
                'label' => $c[0],
                'answer' => isset($answers[$key]) ? ($c[1][$answers[$key]['answer']] ?? $answers[$key]['answer']) : null,
                'detail' => $answers[$key]['detail'] ?? null,
                'concern' => in_array($answers[$key]['answer'] ?? null, PollingUnitCheck::CONCERNS[$key] ?? [], true),
            ])->values()->all(),
            'notes' => $check->notes,
            'photos' => $check->photos()->orderBy('captured_at')->orderBy('id')->get()->map(static fn (Media $m): array => [
                'id' => $m->id,
                'kind' => $m->kind,
                'url' => $m->temporaryUrl(15),
                'distanceM' => $m->distance_from_subject_m === null ? null : (float) $m->distance_from_subject_m,
            ])->values()->all(),
        ];
    }

    private function supervisor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->supervises(), 403);

        return $user;
    }
}
