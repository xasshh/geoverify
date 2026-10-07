<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Campaign\Actions\ManageClients;
use App\Domain\Campaign\Models\ClientOrganisation;
use App\Domain\Campaign\Models\ClientUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The commissioning clients and their logins.
 *
 * A campaign belongs to a client, so this is where a new piece of work starts.
 * Rules live in ManageClients; this only reads and passes along.
 */
final class ClientController
{
    public function index(): Response
    {
        $organisations = ClientOrganisation::query()
            ->withCount('campaigns')
            ->with(['users' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/Clients', [
            'clients' => $organisations->map(static fn (ClientOrganisation $org): array => [
                'id' => $org->id,
                'name' => $org->name,
                'shortCode' => $org->short_code,
                'contactName' => $org->contact_name,
                'contactEmail' => $org->contact_email,
                'contactPhone' => $org->contact_phone,
                'status' => $org->status,
                'campaignCount' => $org->campaigns_count,
                // Fixed once a campaign code carries it.
                'shortCodeLocked' => $org->campaigns_count > 0,
                'users' => $org->users->map(static fn (ClientUser $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'lastSignedInAt' => $user->last_signed_in_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, ManageClients $clients): RedirectResponse
    {
        $organisation = $clients->createOrganisation($this->admin(), $this->validated($request));

        return back()->with('status', "{$organisation->name} added. Add a login so they can read their campaigns.");
    }

    public function update(Request $request, ClientOrganisation $client, ManageClients $clients): RedirectResponse
    {
        $clients->updateOrganisation($this->admin(), $client, $this->validated($request));

        return back()->with('status', "{$client->name} saved.");
    }

    public function status(Request $request, ClientOrganisation $client, ManageClients $clients): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $clients->setOrganisationStatus($this->admin(), $client, $data['status'], $data['reason'] ?? null);

        return back()->with('status', $data['status'] === 'suspended'
            ? "{$client->name} suspended. Its logins can no longer sign in."
            : "{$client->name} is active again.");
    }

    public function addUser(Request $request, ClientOrganisation $client, ManageClients $clients): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
        ]);

        $result = $clients->addUser($this->admin(), $client, $data['name'], $data['email']);

        return back()->with('status', sprintf(
            '%s can sign in at /client/sign-in as %s. First password: %s (shown once, hand it over privately).',
            $result['user']->name,
            $result['user']->email,
            $result['password'],
        ));
    }

    public function userStatus(Request $request, ClientUser $user, ManageClients $clients): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:active,suspended']]);

        $clients->setUserStatus($this->admin(), $user, $data['status']);

        return back()->with('status', "{$user->name} is now {$data['status']}.");
    }

    public function resetPassword(ClientUser $user, ManageClients $clients): RedirectResponse
    {
        $password = $clients->resetPassword($this->admin(), $user);

        return back()->with('status', "New password for {$user->email}: {$password} (shown once). Their other sessions are signed out.");
    }

    /**
     * @return array{name: string, short_code: string, contact_name?: string|null, contact_email?: string|null, contact_phone?: string|null}
     */
    private function validated(Request $request): array
    {
        /** @var array{name: string, short_code: string, contact_name?: string|null, contact_email?: string|null, contact_phone?: string|null} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'short_code' => ['required', 'string', 'max:8'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
        ]);

        return $data;
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
