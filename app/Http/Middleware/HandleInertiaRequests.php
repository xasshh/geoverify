<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Catalogue\Actions\ReadListingStrength;
use App\Domain\Claim\Actions\CountClaimsAwaitingDecision;
use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\Inspection;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Domain\Enumerate\Actions\ReadEnumeratePrices;
use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Models\EnumerateOrganisation;
use App\Domain\Enumerate\Models\EnumerateProject;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\EnumerateTicket;
use App\Domain\Enumerate\Models\EnumerateVisit;
use App\Domain\Field\Actions\FieldMessaging;
use App\Domain\Field\Models\FieldMessage;
use App\Domain\Investment\Models\InvestorUser;
use App\Domain\Party\Actions\ActingParty;
use App\Domain\Party\Actions\SignInWithGoogle;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Actions\CountCorrectionsAwaitingReview;
use App\Domain\Verification\Actions\CountEscalations;
use App\Domain\Verification\Actions\CountObservationsAwaitingReview;
use App\Domain\Verification\Actions\CountOrdersAwaitingAssignment;
use App\Domain\Verification\Models\VerificationOrder;
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
                'investor' => $this->investor($request),
            ],

            // What the console sidebar puts against its queues. Only
            // for the people who can act on it, and only on the screens that
            // show it: this is two counts on every navigation, which is cheap
            // for a supervisor and pointless everywhere else.
            'console' => $this->consoleQueues($request),

            'flash' => [
                'status' => $request->session()->get('status'),
            ],

            // Enumerate checks are free for now: the screens hide the wallet.
            'enumerateFree' => ReadEnumeratePrices::free(),

            // Whether "Continue with Google" is set up on this server.
            'googleSignIn' => SignInWithGoogle::configured(),

            // Which surfaces are open to the public; the closed ones' links hide.
            'surfaces' => [
                'portal' => EnsureSurfaceOpen::open('portal'),
                'invest' => EnsureSurfaceOpen::open('invest'),
            ],
            //
        ];
    }

    /**
     * The waiting counts behind the sidebar.
     *
     * Null off the console and null for anyone who cannot act on them, so the
     * shape itself says whether the numbers mean anything rather than leaving a
     * zero to be read as "nothing waiting".
     *
     * @return array<string, int>|null
     */
    private function consoleQueues(Request $request): ?array
    {
        $user = $request->user('web');

        if (! $user instanceof User || ! $user->supervises()) {
            return null;
        }

        if (! $request->is('console', 'console/*', 'admin/*')) {
            return null;
        }

        return [
            'review' => app(CountObservationsAwaitingReview::class)(),
            'claims' => app(CountClaimsAwaitingDecision::class)(),
            'corrections' => app(CountCorrectionsAwaitingReview::class)(),
            // The one that costs money if it is left alone: every one of these
            // is somebody who has paid and is owed a visit by a date.
            'orders' => app(CountOrdersAwaitingAssignment::class)(),
            // Counted only for the people who can act on it. A supervisor
            // seeing a number they cannot clear is a number that never moves.
            'escalations' => $user->administers() ? app(CountEscalations::class)() : 0,
            // Paid inspections and visits nobody has been sent to yet.
            'inspections' => Inspection::query()
                ->where('status', Inspection::REQUESTED)
                ->whereHas('order', static fn ($q) => $q->where('status', PurchaseStatus::Held->value))
                ->count(),
            // Enumerate requests whose registry answers wait on a supervisor.
            'deskChecks' => EnumerateRequest::query()
                ->whereIn('status', [RequestStatus::Paid->value, RequestStatus::RegistryCheck->value])
                ->count(),
            // Enumerate requests waiting for an officer, and visit reports
            // waiting to be read: both are somebody's paid check standing still.
            'enumerateVisits' => EnumerateRequest::query()->where('status', RequestStatus::AwaitingAgent->value)->count()
                + EnumerateVisit::query()->where('status', EnumerateVisit::SUBMITTED)->count(),
            // Enumerate organisations waiting for an admin's approval, and
            // projects nobody has picked up; only an admin can act on them.
            'organisations' => $user->administers()
                ? EnumerateOrganisation::query()->where('status', EnumerateOrganisation::PENDING)->count()
                    + EnumerateProject::query()->where('status', 'requested')->count()
                : 0,
            // Enumerate complaints waiting for the desk's answer.
            'support' => EnumerateTicket::query()->where('status', EnumerateTicket::OPEN)->count(),
            // Replies from officers this supervisor has not read yet.
            'messages' => FieldMessage::query()
                ->whereIn('officer_id', app(FieldMessaging::class)->teamOf($user)->pluck('id'))
                ->where('direction', FieldMessage::FROM_OFFICER)
                ->whereNull('read_at')
                ->count(),
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

        $acting = app(ActingParty::class)->forRequest($request, $account);

        return [
            'id' => $account->id,
            'name' => $account->name,
            'role' => $acting?->role->value,
            'business' => $this->portalBusiness($account),
            // Invitations waiting on this person: somebody added their number
            // to a business and they have not said yes yet.
            'invitations' => PartyUser::query()
                ->where('portal_account_id', $account->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->count(),
        ];
    }

    /**
     * The signed-in investor and their organisation's standing, or null.
     *
     * @return array{name: string, title: string|null, organisation: string|null, verified: bool}|null
     */
    private function investor(Request $request): ?array
    {
        $investor = $request->user('investor');

        if (! $investor instanceof InvestorUser) {
            return null;
        }

        return [
            'name' => $investor->name,
            'title' => $investor->title,
            'organisation' => $investor->organisation?->name,
            'verified' => $investor->isVerified(),
        ];
    }

    /**
     * The business the portal's sidebar is about: the one most recently
     * established, which is the same one the dashboard opens on. Null until
     * the account controls a listing. The count is orders not yet settled, so
     * the sidebar can say something is moving without anybody opening it.
     *
     * @return array{id: int, name: string, place: string|null, openOrders: int, strength: array{percent: int, missing: list<string>, hint: string}}|null
     */
    private function portalBusiness(PortalAccount $account): ?array
    {
        $partyIds = PartyUser::query()
            ->where('portal_account_id', $account->id)
            ->whereNull('revoked_at')
            ->whereNotNull('accepted_at')
            ->pluck('party_id')
            ->all();

        if ($partyIds === []) {
            return null;
        }

        $control = PartyBusiness::query()
            ->with(['enterprise.structure.ward', 'enterprise.structure.lga'])
            ->whereIn('party_id', $partyIds)
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->orderByDesc('established_at')
            ->first();

        $enterprise = $control?->enterprise;

        if ($enterprise === null) {
            return null;
        }

        $structure = $enterprise->structure;

        $openOrders = VerificationOrder::query()
            ->where('enterprise_id', $enterprise->id)
            ->get(['id', 'status'])
            ->filter(static fn (VerificationOrder $order): bool => ! $order->status->isSettled())
            ->count()
            // And product orders paid for and waiting to be packed and sent.
            + PurchaseOrder::query()
                ->where('seller_party_id', $control->party_id)
                ->where('status', PurchaseStatus::Held->value)
                ->count();

        $place = implode(', ', array_filter([$structure->ward?->name, $structure->lga?->name]));

        return [
            'id' => $enterprise->id,
            'name' => $enterprise->trading_name,
            'place' => $place === '' ? null : $place,
            'openOrders' => $openOrders,
            'strength' => app(ReadListingStrength::class)($enterprise),
        ];
    }
}
