<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Party\Models\PortalAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            // Shared so every screen can show who is signed in, and so the field
            // client knows whose work it is holding without a second request.
            //
            // The guard is named rather than left to the default. Since the
            // portal arrived there are two, and $request->user() answers for
            // whichever is current: on a portal request that is a PortalAccount,
            // which has no role and is not staff. Asking the web guard by name
            // means a portal session can never be mistaken for one.
            'auth' => [
                'user' => $this->staff($request),
                'portal' => $this->portal($request),
            ],

            'flash' => [
                'status' => $request->session()->get('status'),
            ],
            //
        ];
    }

    /**
     * The signed-in staff member, or null.
     *
     * @return array<string, mixed>|null
     */
    private function staff(Request $request): ?array
    {
        $user = $request->user('web');

        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'roleLabel' => $user->role->label(),
            'staffRef' => $user->staff_ref,
        ];
    }

    /**
     * The signed-in party account, or null. Never both at once in practice,
     * but the shape does not assume it.
     *
     * @return array<string, mixed>|null
     */
    private function portal(Request $request): ?array
    {
        $account = $request->user('portal');

        if (! $account instanceof PortalAccount) {
            return null;
        }

        return ['id' => $account->id, 'name' => $account->name];
    }
}
