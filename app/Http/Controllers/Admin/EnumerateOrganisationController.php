<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Campaign\Models\Campaign;
use App\Domain\Enumerate\Actions\ManageOrganisations;
use App\Domain\Enumerate\Actions\ManageProjects;
use App\Domain\Enumerate\Models\EnumerateOrganisation;
use App\Domain\Enumerate\Models\EnumerateProject;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Enumerate organisations, from the inside: approving them (decision 10),
 * giving each an account manager, and moving their projects on to the
 * campaigns that run them. Admins only, like investor KYC.
 */
final class EnumerateOrganisationController
{
    public function index(): Response
    {
        return Inertia::render('admin/EnumerateOrganisations', [
            'organisations' => EnumerateOrganisation::query()
                ->with(['accountManager'])
                ->withCount(['liveMembers as seats'])
                ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->map(static fn (EnumerateOrganisation $o): array => [
                    'id' => $o->id,
                    'name' => $o->name,
                    'rcNumber' => $o->rc_number,
                    'email' => $o->contact_email,
                    'status' => $o->status,
                    'note' => $o->decision_note,
                    'seats' => (int) $o->getAttribute('seats'),
                    'manager' => $o->accountManager?->name,
                    'managerId' => $o->account_manager_id,
                    'openedAt' => $o->created_at?->toIso8601String(),
                ])->values()->all(),
            'projects' => EnumerateProject::query()
                ->with(['organisation', 'campaign'])
                ->orderByRaw("CASE status WHEN 'requested' THEN 0 WHEN 'scoping' THEN 1 WHEN 'live' THEN 2 ELSE 3 END")
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->map(static fn (EnumerateProject $p): array => [
                    'id' => $p->id,
                    'reference' => $p->reference,
                    'name' => $p->name,
                    'organisation' => $p->organisation?->name,
                    'subject' => $p->subject,
                    'area' => $p->area,
                    'target' => $p->target_records,
                    'wantedBy' => $p->wanted_by?->toDateString(),
                    'fields' => $p->fields,
                    'notes' => $p->notes,
                    'status' => $p->status,
                    'campaignId' => $p->campaign_id,
                    'campaign' => $p->campaign?->code,
                ])->values()->all(),
            'managers' => User::query()->whereIn('role', ['supervisor', 'admin'])->where('status', User::STATUS_ACTIVE)->orderBy('name')
                ->get(['id', 'name'])->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all(),
            'campaigns' => Campaign::query()->orderByDesc('id')->limit(100)->get(['id', 'code', 'name'])
                ->map(static fn (Campaign $c): array => ['id' => $c->id, 'label' => "{$c->code} · {$c->name}"])->all(),
            'statuses' => EnumerateProject::STATUSES,
        ]);
    }

    public function decide(Request $request, EnumerateOrganisation $organisation, ManageOrganisations $organisations): RedirectResponse
    {
        $input = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $organisations->decide($organisation, self::admin($request), (bool) $input['approve'], $input['note'] ?? null), 'Ruling recorded.');
    }

    public function manager(Request $request, EnumerateOrganisation $organisation, ManageOrganisations $organisations): RedirectResponse
    {
        $input = $request->validate(['manager_id' => ['required', 'integer']]);

        return $this->attempt(fn () => $organisations->assignManager($organisation, self::admin($request), User::query()->findOrFail((int) $input['manager_id'])), 'Account manager assigned.');
    }

    public function project(Request $request, EnumerateProject $project, ManageProjects $projects): RedirectResponse
    {
        $input = $request->validate(['status' => ['required', 'string'], 'campaign_id' => ['nullable', 'integer']]);

        return $this->attempt(fn () => $projects->move($project, self::admin($request), $input['status'], isset($input['campaign_id']) ? (int) $input['campaign_id'] : null), 'Project updated.');
    }

    private static function admin(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->administers(), 403);

        return $user;
    }

    private function attempt(callable $action, string $done): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->withErrors(['organisation' => $e->getMessage()]);
        }

        return back()->with('status', $done);
    }
}
