<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Party\Actions\ActingParty;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Actions\InitialisePayment;
use App\Domain\Verification\Actions\PlaceOrder;
use App\Domain\Verification\Actions\ResolveServiceZone;
use App\Domain\Verification\Actions\ResolveVerificationPrice;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Enums\OrderUrgency;
use App\Domain\Verification\Enums\ServiceZone;
use App\Domain\Verification\Models\VerificationOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Buying a rung of the ladder.
 *
 * Four steps and no more: see what it costs, place it, pay it, watch it. The
 * fee and every condition attached to it are on one screen above the button,
 * which is what MoneyPanel exists to guarantee.
 *
 * The return from the payment page is deliberately inert. It renders the order
 * as the database currently has it, and if the webhook has not landed yet it
 * says so rather than guessing: a customer's browser is not evidence that money
 * moved, and treating it as evidence would let anybody pay for nothing by
 * visiting a URL.
 */
final class OrderController
{
    /** What a customer is buying, said in their words rather than ours. */
    private const TIERS = [
        'location_verified' => [
            'name' => 'Location verification',
            'buys' => 'An officer visits your business, records where it is, and photographs the front.',
            'happens' => 'An officer attends in person, takes a GPS fix at your door, photographs the frontage and signage, and files a report a supervisor checks. Your listing then shows Location verified with the date it was established.',
        ],
        'operations_verified' => [
            'name' => 'Operations verification',
            'buys' => 'A longer visit: an officer observes that you are trading, and sights your documents.',
            'happens' => 'An officer attends, records signage and staff present, observes trading, and sights the documents you hold. A supervisor checks the report. Your listing then shows Operations verified.',
        ],
    ];

    public function __construct(
        private readonly ActingParty $acting,
        private readonly ResolveServiceZone $zones,
        private readonly ResolveVerificationPrice $prices,
        private readonly PlaceOrder $orders,
    ) {}

    /** The money screen. Everything disclosed before anything is committed. */
    public function create(Request $request, Enterprise $enterprise, string $tier): Response
    {
        $membership = $this->membership($request);
        $this->assertControls($membership, $enterprise);

        if (! array_key_exists($tier, self::TIERS)) {
            throw new AccessDeniedHttpException('That is not something you can buy here.');
        }

        $enterprise->loadMissing('structure.ward');
        $zone = ($this->zones)($enterprise->structure);

        $urgency = OrderUrgency::tryFrom((string) $request->query('urgency')) ?? OrderUrgency::Standard;
        $price = ($this->prices)($tier, $urgency, $zone);

        return Inertia::render('portal/Order', [
            'business' => [
                'id' => $enterprise->id,
                'tradingName' => $enterprise->trading_name,
                'ward' => $enterprise->structure->ward?->name,
            ],
            'tier' => ['key' => $tier, ...self::TIERS[$tier]],
            'urgency' => $urgency->value,
            'zone' => ['code' => $zone->value, 'label' => $zone->label()],
            'terms' => [
                'feeNaira' => (int) round($price->amount_minor / 100),
                'buys' => self::TIERS[$tier]['buys'],
                'within' => $this->within($price->sla_working_days),
                // How many paid visits are already waiting on this ground. A
                // real number or nothing: an invented queue position is the
                // fastest way to stop being believed about anything else.
                'queueAhead' => $this->queueAhead($enterprise),
                'establishes' => self::TIERS[$tier]['name'],
                'whatHappens' => self::TIERS[$tier]['happens'],
            ],
            // Offered only where a price for it exists. A button that quotes
            // one price and then refuses is worse than no button.
            'expressAvailable' => $this->expressExists($tier, $zone),
        ]);
    }

    /** Places the order. No money has moved yet and the screen says so. */
    public function store(Request $request, Enterprise $enterprise): RedirectResponse
    {
        $membership = $this->membership($request);

        $data = $request->validate([
            'tier' => ['required', Rule::in(array_keys(self::TIERS))],
            'urgency' => ['required', Rule::in(array_column(OrderUrgency::cases(), 'value'))],
        ]);

        /** @var PortalAccount $account */
        $account = Auth::guard('portal')->user();

        /** @var Party $party */
        $party = $membership->party;

        try {
            $order = ($this->orders)(
                $party,
                $account,
                $enterprise,
                $data['tier'],
                OrderUrgency::from($data['urgency']),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['tier' => $e->getMessage()]);
        }

        return redirect()->route('portal.orders.show', $order);
    }

    /** One order, as it stands. */
    public function show(Request $request, VerificationOrder $order): Response
    {
        $membership = $this->membership($request);

        if ($order->party_id !== $membership->party_id) {
            throw new AccessDeniedHttpException('That order is not yours.');
        }

        $order->loadMissing(['enterprise', 'structure.ward']);

        return Inertia::render('portal/OrderStatus', [
            'order' => [
                'id' => $order->id,
                'reference' => $order->reference,
                'tier' => self::TIERS[$order->tier]['name'] ?? $order->tier,
                'urgency' => $order->urgency->value,
                'feeNaira' => (int) round($order->amount_minor / 100),
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'outcome' => $order->outcome?->label(),
                'paidAt' => $order->paid_at?->toIso8601String(),
                'dueBy' => $order->due_by?->toDateString(),
                'completedAt' => $order->completed_at?->toIso8601String(),
                'cancellationReason' => $order->cancellation_reason,
                'businessName' => $order->enterprise?->trading_name,
                'businessId' => $order->enterprise_id,
                'within' => $this->within($order->sla_working_days),
            ],
            'payable' => $order->status === OrderStatus::AwaitingPayment,

            // The certificate exists once the work is accepted and not before.
            // Decided here rather than in the page from the status string, so
            // the button cannot appear over an order whose visit was refunded
            // or is still out.
            'certificate' => $order->status === OrderStatus::Completed,
        ]);
    }

    /** Sends the customer to the provider's checkout page. */
    public function pay(Request $request, VerificationOrder $order, InitialisePayment $payments): RedirectResponse
    {
        $membership = $this->membership($request);

        if ($order->party_id !== $membership->party_id) {
            throw new AccessDeniedHttpException('That order is not yours.');
        }

        /** @var PortalAccount $account */
        $account = Auth::guard('portal')->user();

        // A party signs in with a phone, so there may be no email on file. The
        // provider needs one for the receipt, and a deterministic address built
        // from the reference is better than blocking the sale on a form field
        // nobody wants to fill in.
        $email = $account->email ?? "{$order->reference}@orders.geoverify.ng";

        try {
            $url = $payments($order, strtolower($email), route('portal.orders.return', $order));
        } catch (RuntimeException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    /**
     * The customer comes back from the payment page.
     *
     * Changes nothing. Not one field, not one ledger entry. Whatever the
     * provider appended to this URL is a message to the customer, not to us,
     * and the order will say it is paid when the signed webhook says so.
     */
    public function return(Request $request, VerificationOrder $order): RedirectResponse
    {
        $membership = $this->membership($request);

        if ($order->party_id !== $membership->party_id) {
            throw new AccessDeniedHttpException('That order is not yours.');
        }

        return redirect()
            ->route('portal.orders.show', $order)
            ->with('status', $order->status === OrderStatus::AwaitingPayment
                ? 'Thank you. We are waiting for your bank to confirm the payment, which usually takes a few seconds. This page will show the confirmation.'
                : 'Payment confirmed. Your visit is in the queue.');
    }

    private function within(int $workingDays): string
    {
        return "{$workingDays} working days";
    }

    /** How many paid visits are already waiting in this ward. */
    private function queueAhead(Enterprise $enterprise): ?int
    {
        $ward = $enterprise->structure->ward_id;

        if ($ward === null) {
            return null;
        }

        return VerificationOrder::query()
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Assigned->value])
            ->whereHas('structure', static fn ($q) => $q->where('ward_id', $ward))
            ->count();
    }

    private function expressExists(string $tier, ServiceZone $zone): bool
    {
        try {
            ($this->prices)($tier, OrderUrgency::Express, $zone);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function assertControls(PartyUser $membership, Enterprise $enterprise): void
    {
        $controls = PartyBusiness::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('party_id', $membership->party_id)
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->exists();

        if (! $controls) {
            throw new AccessDeniedHttpException('You do not manage this business.');
        }
    }

    private function membership(Request $request): PartyUser
    {
        $account = Auth::guard('portal')->user();

        if (! $account instanceof PortalAccount) {
            throw new AccessDeniedHttpException('Not signed in.');
        }

        $membership = $this->acting->forRequest($request, $account);

        if (! $membership instanceof PartyUser) {
            throw new AccessDeniedHttpException('Choose which business you are acting for.');
        }

        return $membership;
    }
}
