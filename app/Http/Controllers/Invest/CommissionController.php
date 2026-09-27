<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invest;

use App\Domain\Investment\Actions\ReadOpportunities;
use App\Domain\Investment\Models\InvestorUser;
use App\Domain\Investment\Models\Opportunity;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Actions\InitialisePayment;
use App\Domain\Verification\Actions\PlaceOrder;
use App\Domain\Verification\Actions\ResolveServiceZone;
use App\Domain\Verification\Actions\ResolveVerificationPrice;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Enums\OrderUrgency;
use App\Domain\Verification\Enums\ServiceZone;
use App\Domain\Verification\Exports\LoopbackPrint;
use App\Domain\Verification\Exports\PdfRenderer;
use App\Domain\Verification\Models\VerificationEvent;
use App\Domain\Verification\Models\VerificationOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An investor pays for a field visit to a business before committing.
 *
 * The same product a business buys for itself: the same price table, the same
 * SLA, the same officer visit, the same signed webhook and ledger, and the same
 * certificate. Only the payer differs, and only a business whose opportunity
 * the investor can see may be commissioned.
 *
 * Nothing here marks money received. InitialisePayment asks the provider for a
 * checkout page; the order becomes paid only when HandlePaymentWebhook records
 * the provider's signed notice, exactly as for a business.
 */
final class CommissionController
{
    /**
     * What each visit buys, in an investor's words. Keyed by the tiers the
     * price table holds, so the offer and the price can never disagree.
     *
     * @var array<string, array{name: string, buys: string, happens: string}>
     */
    public const TIERS = [
        'location_verified' => [
            'name' => 'Site re-verification',
            'buys' => 'A field agent confirms the business is where it says, and that it is operating.',
            'happens' => 'An agent attends in person, takes a GPS fix at the premises, photographs the frontage and signage, and files a report a supervisor checks. You receive the certificate when the report is accepted.',
        ],
        'operations_verified' => [
            'name' => 'Due diligence visit',
            'buys' => 'A longer visit: an agent observes trading, counts staff on site and sights documents.',
            'happens' => 'An agent attends, records signage and staff present, observes trading and sights the documents the business holds. A supervisor checks the report before it is certified.',
        ],
    ];

    public function __construct(
        private readonly ReadOpportunities $opportunities,
        private readonly ResolveServiceZone $zones,
        private readonly ResolveVerificationPrice $prices,
    ) {}

    /** Every visit this organisation has commissioned, and the picker to start another. */
    public function index(Request $request): Response
    {
        $investor = $this->investor($request);

        $orders = VerificationOrder::query()
            ->with('enterprise:id,trading_name')
            ->where('investor_organisation_id', $investor->investor_organisation_id)
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('invest/Verifications', [
            'orders' => $orders->map(fn (VerificationOrder $o): array => $this->row($o))->values()->all(),
            'opportunities' => array_map(static fn (array $r): array => [
                'id' => $r['id'],
                'name' => $r['name'],
                'sector' => $r['sector'],
                'state' => $r['state'],
                'lga' => $r['lga'],
                'score' => $r['score'],
                'lastVerified' => $r['lastVerified'],
            ], $this->opportunities->rows($investor->investor_organisation_id)),
        ]);
    }

    public function create(Request $request, Opportunity $opportunity): Response
    {
        $investor = $this->investor($request);
        $row = $this->visible($opportunity, $investor);

        $tier = (string) $request->query('tier', 'location_verified');
        abort_unless(array_key_exists($tier, self::TIERS), 404);

        $enterprise = $this->enterprise($opportunity);
        $zone = ($this->zones)($enterprise->structure);
        $urgency = OrderUrgency::tryFrom((string) $request->query('urgency')) ?? OrderUrgency::Standard;
        $price = ($this->prices)($tier, $urgency, $zone);

        return Inertia::render('invest/Commission', [
            'opportunity' => [
                'id' => $row['id'],
                'name' => $row['name'],
                'place' => implode(' · ', array_filter([$row['lga'], $row['state']])),
                'score' => $row['score'],
                'lastVerified' => $row['lastVerified'],
            ],
            'tiers' => array_map(
                fn (string $key): array => ['key' => $key, ...self::TIERS[$key], 'feeNaira' => $this->fee($key, OrderUrgency::Standard, $zone)],
                array_keys(self::TIERS),
            ),
            'tier' => $tier,
            'urgency' => $urgency->value,
            'zone' => $zone->label(),
            'quote' => [
                'feeNaira' => (int) round($price->amount_minor / 100),
                'within' => $price->sla_working_days.' working days',
            ],
            'expressAvailable' => $this->fee($tier, OrderUrgency::Express, $zone) !== null,
        ]);
    }

    public function store(Request $request, Opportunity $opportunity, PlaceOrder $place): RedirectResponse
    {
        $investor = $this->investor($request);
        $this->visible($opportunity, $investor);

        $data = $request->validate([
            'tier' => ['required', Rule::in(array_keys(self::TIERS))],
            'urgency' => ['required', Rule::enum(OrderUrgency::class)],
        ]);

        try {
            $order = $place->forInvestor(
                $investor,
                $this->enterprise($opportunity),
                $data['tier'],
                OrderUrgency::from($data['urgency']),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['tier' => str_starts_with($e->getMessage(), 'You already have')
                ? 'That verification is already under way for this business. You will see its result here when it is certified.'
                : $e->getMessage()]);
        }

        return to_route('invest.verifications.show', $order);
    }

    public function show(Request $request, VerificationOrder $order): Response
    {
        $investor = $this->investor($request);
        $this->owns($order, $investor);

        $order->loadMissing('enterprise:id,trading_name');

        return Inertia::render('invest/Verification', [
            'order' => $this->row($order) + [
                'buys' => self::TIERS[$order->tier]['buys'] ?? null,
                'happens' => self::TIERS[$order->tier]['happens'] ?? null,
                'within' => $order->sla_working_days.' working days',
                'steps' => [
                    ['label' => 'Commissioned', 'at' => $order->created_at?->toDateString(), 'reached' => true],
                    ['label' => 'Paid', 'at' => $order->paid_at?->toDateString(), 'reached' => $order->paid_at !== null],
                    ['label' => 'Agent assigned', 'at' => null, 'reached' => $order->assignment_id !== null],
                    ['label' => 'Report accepted', 'at' => $order->completed_at?->toDateString(), 'reached' => $order->completed_at !== null],
                ],
                'cancellationReason' => $order->getAttribute('cancellation_reason'),
            ],
            'payable' => $order->status === OrderStatus::AwaitingPayment,
        ]);
    }

    public function pay(Request $request, VerificationOrder $order, InitialisePayment $payments): HttpResponse
    {
        $investor = $this->investor($request);
        $this->owns($order, $investor);

        try {
            $url = $payments($order, mb_strtolower($investor->email), route('invest.verifications.return', $order));
        } catch (RuntimeException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        // Inertia::location, not redirect()->away: the form posts over XHR, which
        // cannot follow a redirect to another origin. A plain request still
        // gets an ordinary redirect.
        return Inertia::location($url);
    }

    /**
     * Where the provider sends the investor back. Inert by construction: it
     * reads the order and says what it sees, and never records a payment.
     */
    public function return(Request $request, VerificationOrder $order): RedirectResponse
    {
        $investor = $this->investor($request);
        $this->owns($order, $investor);

        return to_route('invest.verifications.show', $order)->with(
            'status',
            $order->status === OrderStatus::AwaitingPayment
                ? 'Thank you. We are waiting for your bank to confirm the payment, which usually takes a few seconds.'
                : 'Payment confirmed. An agent will be assigned.',
        );
    }

    /**
     * The certificate of a visit this organisation paid for. Authorised by
     * the order rather than by the opportunity, so a business withdrawing
     * later does not take back a report that was bought while it consented.
     */
    public function certificate(Request $request, VerificationOrder $order, PdfRenderer $renderer): StreamedResponse
    {
        $investor = $this->investor($request);
        $this->owns($order, $investor);

        abort_unless($order->status === OrderStatus::Completed, 404, 'This verification has not been completed yet.');
        abort_unless($renderer->available(), 503, 'No headless browser is installed on this server, so a certificate cannot be printed.');

        $url = URL::temporarySignedRoute('portal.certificate.render', now()->addMinutes(2), ['order' => $order->id], absolute: false);
        $file = tempnam(sys_get_temp_dir(), 'geoverify-certificate-').'.pdf';
        $renderer->render(app(LoopbackPrint::class)->url($request, $url), $file);

        VerificationEvent::recordForInvestor($order, 'certificate.downloaded', $investor);

        return response()->streamDownload(
            function () use ($file): void {
                try {
                    echo (string) file_get_contents($file);
                } finally {
                    @unlink($file);
                }
            },
            'geoverify-'.strtolower($order->reference).'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /** @return array<string, mixed> */
    private function row(VerificationOrder $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'business' => $order->enterprise?->trading_name,
            'tier' => self::TIERS[$order->tier]['name'] ?? str_replace('_', ' ', $order->tier),
            'urgency' => $order->urgency->value,
            'feeNaira' => (int) round($order->amount_minor / 100),
            'status' => $order->status->value,
            'statusLabel' => $order->status->label(),
            'orderedAt' => $order->created_at?->toIso8601String(),
            'dueBy' => $order->due_by?->toDateString(),
            'completedAt' => $order->completed_at?->toDateString(),
            'certificate' => $order->status === OrderStatus::Completed,
        ];
    }

    private function fee(string $tier, OrderUrgency $urgency, ServiceZone $zone): ?int
    {
        try {
            return (int) round(($this->prices)($tier, $urgency, $zone)->amount_minor / 100);
        } catch (RuntimeException) {
            return null;
        }
    }

    private function enterprise(Opportunity $opportunity): Enterprise
    {
        $enterprise = Enterprise::query()->with('structure')->findOrFail($opportunity->enterprise_id);

        return $enterprise;
    }

    private function investor(Request $request): InvestorUser
    {
        $investor = $request->user('investor');
        abort_unless($investor instanceof InvestorUser, 403);

        return $investor;
    }

    /** @return array<string, mixed> */
    private function visible(Opportunity $opportunity, InvestorUser $investor): array
    {
        $row = $this->opportunities->one($opportunity->id, $investor->investor_organisation_id);
        abort_if($row === null, 404);

        return $row;
    }

    private function owns(VerificationOrder $order, InvestorUser $investor): void
    {
        abort_unless($order->investor_organisation_id === $investor->investor_organisation_id, 404);
    }
}
