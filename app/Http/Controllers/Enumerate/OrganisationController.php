<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enumerate;

use App\Domain\Enumerate\Actions\EnumerateContext;
use App\Domain\Enumerate\Actions\ImportBulkVerification;
use App\Domain\Enumerate\Actions\ManageOrganisations;
use App\Domain\Enumerate\Actions\ManageProjects;
use App\Domain\Enumerate\Actions\PresentEnumerateRequest;
use App\Domain\Enumerate\Actions\ReadEnumeratePrices;
use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Enumerate\Models\EnumerateBatch;
use App\Domain\Enumerate\Models\EnumerateBatchRow;
use App\Domain\Enumerate\Models\EnumerateMember;
use App\Domain\Enumerate\Models\EnumerateProject;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Party\Actions\NormalisePhone;
use App\Domain\Party\Models\PortalAccount;
use App\Http\Controllers\Portal\SignInController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Enumerate for Organisations (boards 34 and 35): opening one, its overview,
 * its team, bulk verification and projects.
 *
 * Every page here is for the organisation the person is acting for, found
 * through EnumerateContext; somebody not acting for one is sent to their own
 * home. What a seat may do is asked of the seat (EnumerateMember::may), in the
 * actions, so a page cannot offer what the action would refuse.
 */
final class OrganisationController
{
    public function __construct(
        private readonly EnumerateController $enumerate,
        private readonly EnumerateContext $context,
        private readonly ManageOrganisations $organisations,
    ) {}

    public function create(Request $request): Response
    {
        $account = EnumerateController::account($request);

        return Inertia::render('enumerate/OrganisationNew', ['frame' => $this->enumerate->frame($request, $account)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = EnumerateController::account($request);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'rc_number' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:180'],
        ]);

        try {
            $organisation = $this->organisations->open($account, $input['name'], $input['rc_number'] ?? null, $input['email'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        $this->context->switch($request, $account, $organisation->id);

        return redirect()->route('enumerate.organisation')->with(
            'status',
            'Your organisation is open. You can fund its wallet and run checks now; bulk verification and projects open once we approve it, usually within a working day.',
        );
    }

    public function accept(Request $request, EnumerateMember $member): RedirectResponse
    {
        $account = EnumerateController::account($request);

        try {
            $this->organisations->accept($member, $account);
        } catch (RuntimeException $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        }

        $this->context->switch($request, $account, $member->organisation_id);

        return redirect()->route('enumerate.organisation')->with('status', 'Welcome to the team.');
    }

    public function overview(Request $request, ManageProjects $projects, ReadEnumeratePrices $prices): Response|RedirectResponse
    {
        [$account, $member] = $this->seat($request);

        if ($member === null) {
            return redirect()->route('enumerate.home');
        }

        $organisationId = $member->organisation_id;
        $month = Carbon::now(config('app.timezone'))->startOfMonth();
        $requests = EnumerateRequest::query()->where('organisation_id', $organisationId);
        $thisMonth = (clone $requests)->where('paid_at', '>=', $month)->get(['tier', 'status']);
        $cards = EnumerateProject::query()
            ->where('organisation_id', $organisationId)
            ->where('status', '<>', 'declined')
            ->with('campaign')
            ->latest('id')
            ->limit(4)
            ->get()
            ->map(fn (EnumerateProject $p): array => $this->projectCard($p, $projects))
            ->values()->all();

        return Inertia::render('enumerate/Overview', [
            'frame' => $this->enumerate->frame($request, $account),
            'prices' => $prices->list(),
            'stats' => [
                'month' => $thisMonth->count(),
                'byTier' => [1 => $thisMonth->where('tier', Tier::Registry)->count(), 2 => $thisMonth->where('tier', Tier::Location)->count(), 3 => $thisMonth->where('tier', Tier::Activity)->count()],
                'liveProjects' => EnumerateProject::query()->where('organisation_id', $organisationId)->where('status', 'live')->count(),
                'officersOut' => array_sum(array_map(static fn (array $c): int => $c['status'] === 'live' ? (int) ($c['officers'] ?? 0) : 0, $cards)),
                'needReview' => (clone $requests)->where('status', RequestStatus::Failed->value)->where('completed_at', '>=', $month)->count(),
            ],
            'projects' => $cards,
            'team' => $this->team($organisationId),
            'batches' => EnumerateBatch::query()->where('organisation_id', $organisationId)->latest('id')->limit(5)->get()
                ->map(static fn (EnumerateBatch $b): array => self::batchRow($b))->values()->all(),
        ]);
    }

    public function teamPage(Request $request): Response|RedirectResponse
    {
        [$account, $member] = $this->seat($request);

        if ($member === null) {
            return redirect()->route('enumerate.home');
        }

        return Inertia::render('enumerate/Team', [
            'frame' => $this->enumerate->frame($request, $account),
            'team' => $this->team($member->organisation_id),
            'roles' => EnumerateMember::ROLES,
            'me' => $member->id,
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        [, $member] = $this->seat($request);
        if ($request->filled('email') || ! SignInController::sms()) {
            $input = $request->validate(['email' => ['required', 'email', 'max:180'], 'role' => ['required', 'string']]);

            return $this->attempt(fn () => $this->organisations->inviteByEmail($this->must($member), $input['email'], $input['role']), 'Invitation sent. They get an email, and accept when they sign in to Enumerate.', 'email');
        }

        $input = $request->validate(['phone' => ['required', 'string', 'max:32'], 'role' => ['required', 'string']]);

        return $this->attempt(fn () => $this->organisations->invite($this->must($member), $input['phone'], $input['role']), 'Invitation saved. They see it the next time they sign in to Enumerate with that number.', 'phone');
    }

    public function role(Request $request, EnumerateMember $seat): RedirectResponse
    {
        [, $member] = $this->seat($request);
        $input = $request->validate(['role' => ['required', 'string']]);

        return $this->attempt(fn () => $this->organisations->changeRole($this->must($member), $seat, $input['role']), 'Role changed.', 'phone');
    }

    public function revoke(Request $request, EnumerateMember $seat): RedirectResponse
    {
        [, $member] = $this->seat($request);

        return $this->attempt(fn () => $this->organisations->revoke($this->must($member), $seat), 'Seat removed. Their past checks stay on the record.', 'phone');
    }

    public function bulk(Request $request, ImportBulkVerification $import): RedirectResponse
    {
        [$account, $member] = $this->seat($request);
        $input = $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel', 'max:1024'],
            'tier' => ['required', 'integer', 'in:1,2,3'],
            'days' => ['nullable', 'integer', 'in:7,14,30'],
        ]);

        try {
            $batch = $import(
                $this->must($member),
                $account,
                (string) file_get_contents((string) $request->file('file')?->getRealPath()),
                Tier::from((int) $input['tier']),
                isset($input['days']) ? (int) $input['days'] : null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('enumerate.organisation.batch', $batch->reference)->with(
            'status',
            sprintf('%d %s placed and paid from the organisation wallet.%s', $batch->rows_placed, $batch->rows_placed === 1 ? 'check' : 'checks', $batch->rows_refused > 0 ? " {$batch->rows_refused} lines could not be checked; see why below." : ''),
        );
    }

    public function batch(Request $request, string $reference, PresentEnumerateRequest $present): Response
    {
        [$account, $member] = $this->seat($request);

        $batch = EnumerateBatch::query()
            ->where('reference', $reference)
            ->where('organisation_id', $this->must($member)->organisation_id)
            ->first() ?? throw new NotFoundHttpException;

        return Inertia::render('enumerate/Batch', [
            'frame' => $this->enumerate->frame($request, $account),
            'batch' => self::batchRow($batch),
            'rows' => $batch->rows()->with('request')->get()->map(static fn (EnumerateBatchRow $r): array => [
                'line' => $r->line,
                'name' => $r->name,
                'rcNumber' => $r->rc_number,
                'outcome' => $r->outcome,
                'reason' => $r->reason,
                'request' => $r->request === null ? null : $present->row($r->request),
            ])->values()->all(),
        ]);
    }

    /** The CSV template the Bulk verification card offers. */
    public function template(): StreamedResponse
    {
        return response()->streamDownload(static function (): void {
            echo "business name,RC/BN number,TIN,address\n";
            echo "Kora Build Supplies Limited,RC 1482093,23984417-0001,\"Plot 7, Ahmadu Bello Way, Garki, Abuja\"\n";
        }, 'enumerate-bulk-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function projectsPage(Request $request, ManageProjects $projects): Response|RedirectResponse
    {
        [$account, $member] = $this->seat($request);

        if ($member === null) {
            return redirect()->route('enumerate.home');
        }

        return Inertia::render('enumerate/Projects', [
            'frame' => $this->enumerate->frame($request, $account),
            'projects' => EnumerateProject::query()->where('organisation_id', $member->organisation_id)->with('campaign')->latest('id')->get()
                ->map(fn (EnumerateProject $p): array => $this->projectCard($p, $projects))->values()->all(),
            'fieldTypes' => EnumerateProject::FIELD_TYPES,
            'start' => $request->boolean('new'),
        ]);
    }

    public function storeProject(Request $request, ManageProjects $projects): RedirectResponse
    {
        [$account, $member] = $this->seat($request);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'subject' => ['required', 'string', 'max:120'],
            'area' => ['required', 'string', 'max:300'],
            'target_records' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'wanted_by' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'fields' => ['required', 'array', 'max:40'],
            'fields.*.label' => ['nullable', 'string', 'max:80'],
            'fields.*.type' => ['required', 'string'],
        ]);

        try {
            $project = $projects->request($this->must($member), $account, [
                'name' => $input['name'],
                'subject' => $input['subject'],
                'area' => $input['area'],
                'target_records' => isset($input['target_records']) ? (int) $input['target_records'] : null,
                'wanted_by' => $input['wanted_by'] ?? null,
                'notes' => $input['notes'] ?? null,
                'fields' => array_map(static fn (array $f): array => ['label' => (string) ($f['label'] ?? ''), 'type' => (string) $f['type']], $input['fields']),
            ]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('enumerate.organisation.project', $project->reference)
            ->with('status', 'Project requested. Your account manager will be in touch to scope it.');
    }

    public function project(Request $request, string $reference, ManageProjects $projects): Response
    {
        [$account, $member] = $this->seat($request);

        $project = EnumerateProject::query()
            ->where('reference', $reference)
            ->where('organisation_id', $this->must($member)->organisation_id)
            ->with('campaign')
            ->first() ?? throw new NotFoundHttpException;

        return Inertia::render('enumerate/Project', [
            'frame' => $this->enumerate->frame($request, $account),
            'project' => $this->projectCard($project, $projects) + [
                'area' => $project->area,
                'notes' => $project->notes,
                'asked' => $project->fields,
                'progress' => $projects->progress($project),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function projectCard(EnumerateProject $project, ManageProjects $projects): array
    {
        $progress = $project->campaign_id === null ? null : $projects->progress($project);

        return [
            'reference' => $project->reference,
            'name' => $project->name,
            'subject' => $project->subject,
            'status' => $project->status,
            'statusLabel' => EnumerateProject::STATUSES[$project->status] ?? $project->status,
            'fieldCount' => count($project->fields),
            // Once a campaign runs it, its target is the one being worked to.
            'target' => $progress['target'] ?? $project->target_records,
            'wantedBy' => $project->wanted_by?->toDateString(),
            'requestedAt' => $project->created_at?->toIso8601String(),
            'records' => $progress['records'] ?? null,
            'percent' => $progress['percent'] ?? null,
            'officers' => $progress['officers'] ?? null,
            'qaPassRate' => $progress['qaPassRate'] ?? null,
            'endsOn' => $progress['timeline']['endsOn'] ?? null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function team(int $organisationId): array
    {
        $phones = new NormalisePhone;

        return EnumerateMember::query()
            ->where('organisation_id', $organisationId)
            ->whereNull('revoked_at')
            ->with('account')
            ->orderByRaw("CASE role WHEN 'admin' THEN 0 WHEN 'project_lead' THEN 1 WHEN 'requester' THEN 2 ELSE 3 END")
            ->orderBy('id')
            ->get()
            ->map(static fn (EnumerateMember $m): array => [
                'id' => $m->id,
                // A seat not yet taken is shown by its masked number, never in full.
                'name' => $m->account !== null && $m->accepted_at !== null
                    ? $m->account->name
                    : ($m->email ?? ($m->phone === null ? 'Invited' : $phones->masked($m->phone))),
                'role' => $m->role,
                'roleLabel' => EnumerateMember::ROLES[$m->role] ?? $m->role,
                'pending' => $m->accepted_at === null,
            ])
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private static function batchRow(EnumerateBatch $batch): array
    {
        return [
            'reference' => $batch->reference,
            'tier' => $batch->tier,
            'monitoringDays' => $batch->monitoring_days,
            'total' => $batch->rows_total,
            'placed' => $batch->rows_placed,
            'refused' => $batch->rows_refused,
            'totalMinor' => $batch->total_minor,
            'at' => $batch->created_at?->toIso8601String(),
        ];
    }

    /** @return array{0: PortalAccount, 1: EnumerateMember|null} */
    private function seat(Request $request): array
    {
        $account = EnumerateController::account($request);

        return [$account, $this->context->member($request, $account)];
    }

    private function must(?EnumerateMember $member): EnumerateMember
    {
        return $member ?? throw new NotFoundHttpException;
    }

    private function attempt(callable $action, string $done, string $field): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->withErrors([$field => $e->getMessage()]);
        }

        return back()->with('status', $done);
    }
}
