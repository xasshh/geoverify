<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Field\Actions\RegisterDevice;
use App\Domain\Field\Models\Device;
use App\Domain\Staff\Actions\CreateStaffMember;
use App\Domain\Staff\Actions\SetStaffStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The staff, and the handsets they carry.
 *
 * One screen for both because the questions are asked together: somebody has
 * left, so close the account and cut off the phone. Keeping them apart in the
 * interface would mean doing half the job and discovering the other half later.
 *
 * Nothing here deletes. A person is suspended and a device is revoked, and both
 * keep everything they ever recorded attributable to them.
 */
final class PeopleController
{
    public function index(): Response
    {
        $people = DB::select(<<<'SQL'
            select
                users.id, users.name, users.email, users.role, users.status,
                users.staff_ref, users.last_active_at,
                (select count(*) from assignments
                  where assignments.user_id = users.id and assignments.closed_at is null) as open_assignments,
                (select count(*) from structure_observations
                  where structure_observations.captured_by = users.id) as captures
            from users
            order by users.role, users.name
        SQL);

        $devices = Device::query()
            ->with('user:id,name,staff_ref')
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(static fn (Device $device): array => [
                'id' => $device->id,
                'deviceId' => $device->device_id,
                'model' => $device->model,
                'appVersion' => $device->app_version,
                'integrityVerdict' => $device->integrity_verdict,
                'status' => $device->status,
                'lastSeenAt' => $device->last_seen_at?->toIso8601String(),
                'revokedReason' => $device->revoked_reason,
                'officer' => $device->user === null ? null : [
                    'id' => $device->user->id,
                    'name' => $device->user->name,
                    'staffRef' => $device->user->staff_ref,
                ],
            ])
            ->all();

        return Inertia::render('admin/People', [
            'people' => array_map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'role' => (string) $row->role,
                'status' => (string) $row->status,
                'staffRef' => $row->staff_ref,
                'lastActiveAt' => $row->last_active_at,
                'openAssignments' => (int) $row->open_assignments,
                'captures' => (int) $row->captures,
            ], $people),
            'devices' => $devices,
            'roles' => array_map(static fn (Role $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
            ], Role::cases()),
        ]);
    }

    public function store(Request $request, CreateStaffMember $create): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:officer,supervisor,admin'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $result = $create(
                $this->admin(),
                $data['name'],
                $data['email'],
                Role::from($data['role']),
                $data['phone'] ?? null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        // Shown once, on the next screen, and never stored anywhere readable.
        // The admin reads it out and it is gone on the following navigation.
        return back()->with('status', sprintf(
            '%s added as %s. First password: %s',
            $result['user']->name,
            $result['user']->staff_ref ?? '',
            $result['password'],
        ));
    }

    public function status(Request $request, User $person, SetStaffStatus $set): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended'],
            'reason' => ['required', 'string', 'min:8', 'max:300'],
        ]);

        try {
            $set($this->admin(), $person, $data['status'], $data['reason']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Status changed.');
    }

    public function revokeDevice(Request $request, Device $device, RegisterDevice $devices): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:300'],
        ]);

        try {
            $devices->revoke($device, $this->admin(), $data['reason']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Device revoked.');
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
