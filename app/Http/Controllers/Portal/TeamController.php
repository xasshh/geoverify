<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Party\Actions\ActingParty;
use App\Domain\Party\Actions\ManageTeam;
use App\Domain\Party\Actions\NormalisePhone;
use App\Domain\Party\Enums\PartyRole;
use App\Domain\Party\Models\PartyUser;
use App\Http\Controllers\Portal\Concerns\ActsForBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/** Team & roles: who acts for this business, and the invitations waiting. */
final class TeamController
{
    use ActsForBusiness;

    public function __construct(private readonly ManageTeam $team) {}

    public function index(Request $request, NormalisePhone $phones): Response
    {
        $membership = $this->membership($request);

        $members = PartyUser::query()
            ->with('account:id,name,phone')
            ->where('party_id', $membership->party_id)
            ->whereNull('revoked_at')
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->get();

        return Inertia::render('portal/Team', [
            'party' => ['name' => $membership->party?->display_name, 'code' => $membership->party?->code],
            'me' => $membership->id,
            'isOwner' => $membership->role->managesAccess(),
            'roles' => [
                ['value' => PartyRole::Manager->value, 'label' => 'Manager', 'can' => 'Edit listings, buy verification, propose corrections'],
                ['value' => PartyRole::Viewer->value, 'label' => 'Viewer', 'can' => 'See everything, change nothing'],
            ],
            'members' => $members->map(static fn (PartyUser $m): array => [
                'id' => $m->id,
                'name' => $m->account?->name,
                'phone' => $m->account === null ? null : $phones->masked($m->account->phone),
                'role' => $m->role->value,
                'roleLabel' => $m->role->label(),
                'pending' => $m->accepted_at === null,
                'since' => ($m->accepted_at ?? $m->invited_at)?->toDateString(),
            ])->all(),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $membership = $this->membership($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'role' => ['required', Rule::in([PartyRole::Manager->value, PartyRole::Viewer->value])],
        ]);

        try {
            $this->team->invite($membership, $data['phone'], $data['name'], PartyRole::from($data['role']));
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['phone' => $e->getMessage()]);
        }

        return back()->with('status', 'Invited. They sign in with a code to that number and accept.');
    }

    public function role(Request $request, PartyUser $member): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in([PartyRole::Manager->value, PartyRole::Viewer->value])]]);

        try {
            $this->team->changeRole($this->membership($request), $member, PartyRole::from($data['role']));
        } catch (RuntimeException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return back()->with('status', 'Role changed.');
    }

    public function revoke(Request $request, PartyUser $member): RedirectResponse
    {
        try {
            $this->team->revoke($this->membership($request), $member);
        } catch (RuntimeException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return back()->with('status', 'Access removed.');
    }

    /** The invitee, signed in on the invited number. Works before they act for anything. */
    public function accept(Request $request, PartyUser $member): RedirectResponse
    {
        try {
            $this->team->accept($member, $this->account($request));
        } catch (RuntimeException) {
            abort(404);
        }

        $request->session()->put(ActingParty::SESSION_KEY, $member->party_id);

        $name = $member->party->display_name ?? 'the business';

        return redirect()->route('portal.dashboard')->with('status', "You now have access to {$name}.");
    }
}
