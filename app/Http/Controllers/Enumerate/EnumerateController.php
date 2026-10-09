<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enumerate;

use App\Domain\Enumerate\Actions\EnumerateContext;
use App\Domain\Enumerate\Actions\ManageRequesterWallet;
use App\Domain\Enumerate\Actions\PresentEnumerateRequest;
use App\Domain\Enumerate\Actions\ReadEnumeratePrices;
use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Models\EnumerateMember;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\EnumerateTicket;
use App\Domain\Enumerate\Models\EnumerateTicketMessage;
use App\Domain\Party\Models\PortalAccount;
use App\Http\Controllers\Portal\SignInController;
use App\Http\Middleware\EnsurePortalAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Enumerate's front door and home.
 *
 * The same portal accounts as the business portal and the marketplace
 * (decision 9), so signing in posts to the portal's own endpoints. What this
 * adds is the door: a person arriving here is sent back here afterwards.
 */
final class EnumerateController
{
    public function __construct(
        private readonly EnumerateContext $context,
        private readonly ManageRequesterWallet $wallets,
        private readonly PresentEnumerateRequest $present,
    ) {}

    public function signIn(Request $request, ReadEnumeratePrices $prices): Response|RedirectResponse
    {
        if (Auth::guard('portal')->check()) {
            return redirect()->route('enumerate.home');
        }

        // Unless they were already on their way to a particular page here,
        // the portal's sign-in brings them back to Enumerate's home.
        $intended = $request->session()->get(EnsurePortalAccount::INTENDED);

        if (! is_string($intended) || ! str_contains($intended, '/enumerate')) {
            $request->session()->put(EnsurePortalAccount::INTENDED, route('enumerate.home'));
        }

        return Inertia::render('enumerate/SignIn', ['prices' => $prices->list(), 'smsEnabled' => SignInController::sms()]);
    }

    public function home(Request $request, ReadEnumeratePrices $prices): Response|RedirectResponse
    {
        $account = self::account($request);

        // Acting for an organisation, home is its overview (board 34).
        if ($this->context->member($request, $account) !== null) {
            return redirect()->route('enumerate.organisation');
        }

        $wallet = $this->context->wallet($request, $account);
        $finished = RequestStatus::finishedValues();

        $requests = EnumerateRequest::query()->where('wallet_id', $wallet->id);

        return Inertia::render('enumerate/Home', [
            'frame' => $this->frame($request, $account),
            'prices' => $prices->list(),
            'counts' => [
                'inProgress' => (clone $requests)->whereNotIn('status', $finished)->count(),
                'completed' => (clone $requests)->whereIn('status', $finished)->count(),
            ],
            'complaints' => [
                'open' => EnumerateTicket::query()->where('portal_account_id', $account->id)->where('status', '<>', EnumerateTicket::RESOLVED)->count(),
                'repliedAt' => EnumerateTicketMessage::query()
                    ->whereNotNull('user_id')
                    ->whereIn('enumerate_ticket_id', EnumerateTicket::query()->where('portal_account_id', $account->id)->where('status', '<>', EnumerateTicket::RESOLVED)->select('id'))
                    ->max('created_at'),
            ],
            // The newest Tier 3 under way, for the live strip under the cards.
            'live' => $this->live((clone $requests)->where('status', RequestStatus::Monitoring->value)->latest('paid_at')->first()),
            'recent' => (clone $requests)->orderByDesc('paid_at')->orderByDesc('id')->limit(5)->get()
                ->map(fn (EnumerateRequest $r): array => $this->present->row($r))->values()->all(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function live(?EnumerateRequest $request): ?array
    {
        $monitoring = $request === null ? null : $this->present->page($request)['monitoring'];

        return $request === null || ! is_array($monitoring) ? null : [
            'reference' => $request->reference,
            'business' => $request->subject_name,
            'days' => $monitoring['days'],
            'dayToday' => $monitoring['dayToday'],
            'calendar' => $monitoring['calendar'],
        ];
    }

    /**
     * What every signed-in page's frame needs: who, whether they are acting
     * for an organisation, the wallet in the header (theirs or its), the count
     * against My verifications, and what the switcher offers.
     *
     * @return array<string, mixed>
     */
    public function frame(Request $request, PortalAccount $account): array
    {
        $member = $this->context->member($request, $account);
        $wallet = $this->context->wallet($request, $account);
        $organisation = $member?->organisation;

        return [
            'name' => $account->name,
            'walletMinor' => $this->wallets->balanceMinor($wallet),
            'active' => EnumerateRequest::query()
                ->where('wallet_id', $wallet->id)
                ->whereNotIn('status', RequestStatus::finishedValues())
                ->count(),
            'organisation' => $organisation === null || $member === null ? null : [
                'id' => $organisation->id,
                'name' => $organisation->name,
                'status' => $organisation->status,
                'role' => $member->role,
                'roleLabel' => EnumerateMember::ROLES[$member->role] ?? $member->role,
                'seats' => $organisation->liveMembers()->count(),
                'accountManager' => $organisation->accountManager?->name,
                'can' => [
                    'request' => $member->may('request'),
                    'bulk' => $member->may('bulk') && $organisation->approved(),
                    'project' => $member->may('project') && $organisation->approved(),
                    'fund' => $member->may('fund'),
                    'team' => $member->may('team'),
                ],
            ],
            'organisations' => $this->context->organisations($account),
            'invitations' => $this->context->invitations($account),
        ];
    }

    /** The switcher: act as oneself, or for one of one's organisations. */
    public function switch(Request $request): RedirectResponse
    {
        $account = self::account($request);
        $to = $request->input('organisation');
        $this->context->switch($request, $account, is_numeric($to) ? (int) $to : null);

        return redirect()->route('enumerate.home');
    }

    public static function account(Request $request): PortalAccount
    {
        $account = $request->user('portal');
        abort_unless($account instanceof PortalAccount, 403);

        return $account;
    }
}
