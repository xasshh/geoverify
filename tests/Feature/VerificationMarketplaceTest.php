<?php

declare(strict_types=1);

use App\Domain\Claim\Actions\GrantControl;
use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Enums\AssignmentStatus;
use App\Domain\Field\Models\Assignment;
use App\Domain\Field\Models\FieldSession;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\Actions\ReadLedgerBalances;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Actions\CaptureStructure;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Actions\AssignVerificationVisit;
use App\Domain\Verification\Actions\CompleteOrder;
use App\Domain\Verification\Actions\PlaceOrder;
use App\Domain\Verification\Actions\RecordPayment;
use App\Domain\Verification\Actions\ResolveServiceZone;
use App\Domain\Verification\Actions\ResolveVerificationPrice;
use App\Domain\Verification\Actions\WorkingDays;
use App\Domain\Verification\Enums\OrderOutcome;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Enums\OrderUrgency;
use App\Domain\Verification\Enums\ServiceZone;
use App\Domain\Verification\Models\PaymentWebhookEvent;
use App\Domain\Verification\Models\VerificationEvent;
use App\Domain\Verification\Models\VerificationOrder;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * M6: somebody pays us to go and look, and an officer goes.
 *
 * The milestone gate, stated as tests. An order is placed and paid, the money
 * is held in our own ledger rather than in a status column, an assignment
 * appears in the console's existing view carrying priority, an officer works it
 * through the capture flow that already exists, supervisor acceptance turns
 * held money into income, and the ledger balances at every point.
 *
 * A replayed webhook changes nothing. A customer's browser changes nothing.
 */
beforeEach(function () {
    // Reference data, not fixtures. Nothing in the marketplace resolves without
    // a price list and a chart of accounts.
    $this->seed(VerificationPricingSeeder::class);
});

/**
 * A claimed shop, its party, and the account acting for it.
 *
 * @return array{party: Party, account: PortalAccount, membership: PartyUser, shop: Enterprise}
 */
function buyerWithShop(string $name = 'Chidi Okonkwo', string $phone = '08039990001'): array
{
    $shop = enumeratedShop('Okonkwo Hardware');
    $who = claimant($name, $phone);

    app(GrantControl::class)->grant(submitClaimFor($who, $shop));

    return [...$who, 'shop' => $shop->refresh()];
}

/** @param  array{party: Party, account: PortalAccount, membership: PartyUser, shop: Enterprise}  $it */
function placeOrderFor(array $it, string $tier = 'location_verified', OrderUrgency $urgency = OrderUrgency::Standard): VerificationOrder
{
    return app(PlaceOrder::class)(
        $it['party'],
        $it['account'],
        $it['shop'],
        $tier,
        $urgency,
    );
}

/**
 * A signed delivery, shaped the way the provider sends one.
 *
 * @return array{payload: array<string, mixed>, raw: string, signature: string}
 */
function paystackDelivery(VerificationOrder $order, int $chargeId = 4242, ?string $paidAt = null): array
{
    config()->set('services.paystack.secret', 'sk_test_marketplace');

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'id' => $chargeId,
            'reference' => $order->reference,
            'amount' => $order->amount_minor,
            'currency' => $order->currency,
            'status' => 'success',
            'paid_at' => $paidAt ?? now()->toIso8601String(),
        ],
    ];

    $raw = json_encode($payload, JSON_THROW_ON_ERROR);

    return [
        'payload' => $payload,
        'raw' => $raw,
        'signature' => hash_hmac('sha512', $raw, 'sk_test_marketplace'),
    ];
}

/**
 * The officer goes, and files it through the flow that already exists.
 *
 * Deliberately not a status nudge. The point of the milestone is that a paid
 * visit is worked with the same client, the same capture action and the same
 * review queue as any other assignment, and a test that set a column instead
 * would prove the opposite of what it claims to.
 */
function officerFilesVisit(VerificationOrder $order, User $officer): StructureObservation
{
    $assignment = Assignment::query()->findOrFail($order->assignment_id);
    $cell = GridCell::query()->findOrFail($assignment->grid_cell_id);

    $session = FieldSession::query()->create([
        'user_id' => $officer->id,
        'assignment_id' => $assignment->id,
        'started_at' => now()->subHours(2),
        'client_uuid' => (string) Str::uuid7(),
    ]);

    [$lon, $lat] = cellCentre($cell);

    $structure = app(CaptureStructure::class)->capture(
        captureFor($cell, [
            'longitude' => $lon,
            'latitude' => $lat,
            'observed_at' => now()->toIso8601String(),
            'field_session_id' => $session->id,
            'assignment_id' => $assignment->id,
        ]),
        $officer,
    );

    return StructureObservation::query()
        ->where('structure_id', $structure->id)
        ->latest('observed_at')
        ->firstOrFail();
}

it('places an order that holds nothing until the money arrives', function () {
    $it = buyerWithShop();

    $order = placeOrderFor($it);

    expect($order->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->reference)->toStartWith('GV-'.now()->format('Y').'-')
        ->and($order->paid_at)->toBeNull()
        // The clock has not started. A promise dated from the order rather than
        // from the payment would spend our SLA on somebody else's slow bank.
        ->and($order->due_by)->toBeNull()
        // And nothing has moved in the ledger.
        ->and(LedgerEntry::query()->count())->toBe(0);

    expect(VerificationEvent::query()->where('event', 'order.placed')->exists())->toBeTrue();
});

it('stamps the price onto the order so a later price change cannot rewrite it', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);

    $charged = $order->amount_minor;

    // The price list moves. Nothing about the agreement does.
    DB::table('verification_prices')->update(['amount_minor' => 99_000_00]);

    expect($order->refresh()->amount_minor)->toBe($charged);
});

it('refuses a second live order for the same tier on the same listing', function () {
    $it = buyerWithShop();
    placeOrderFor($it);

    expect(fn () => placeOrderFor($it))
        ->toThrow(RuntimeException::class, 'already have that verification in progress');
});

it('refuses an order against a listing the party does not manage', function () {
    $it = buyerWithShop();
    $someoneElse = claimant('Amina Bello', '08039990002');

    expect(fn () => app(PlaceOrder::class)(
        $someoneElse['party'],
        $someoneElse['account'],
        $it['shop'],
        'location_verified',
    ))->toThrow(RuntimeException::class, 'do not manage this business');
});

it('takes the money only from a signed webhook, and holds it as a liability', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);

    $delivery = paystackDelivery($order);

    $this->postJson('/webhooks/paystack', $delivery['payload'], [
        'x-paystack-signature' => $delivery['signature'],
    ])->assertOk();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->due_by)->not->toBeNull();

    $balances = app(ReadLedgerBalances::class)();

    expect($balances['balances'])->toBeTrue()
        ->and($balances['accounts'][LedgerAccount::CASH])->toBe($order->amount_minor)
        // Negative because a liability grows with a credit. We are holding the
        // customer's money, and the ledger says so in the only column it has.
        ->and($balances['accounts'][LedgerAccount::CUSTOMER_FUNDS_HELD])->toBe(-$order->amount_minor)
        ->and($balances['accounts'][LedgerAccount::VERIFICATION_INCOME])->toBe(0);
});

it('rejects a webhook that is not signed by the provider', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);
    $delivery = paystackDelivery($order);

    $this->postJson('/webhooks/paystack', $delivery['payload'], [
        'x-paystack-signature' => 'not the right digest',
    ])->assertStatus(401);

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and(LedgerEntry::query()->count())->toBe(0);

    // Recorded anyway. Somebody probing this endpoint is worth knowing about.
    expect(PaymentWebhookEvent::query()->where('signature_verified', false)->count())->toBe(1);
});

it('changes nothing when the same webhook is replayed', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);
    $delivery = paystackDelivery($order);

    foreach (range(1, 4) as $ignored) {
        $this->postJson('/webhooks/paystack', $delivery['payload'], [
            'x-paystack-signature' => $delivery['signature'],
        ])->assertOk();
    }

    expect(LedgerEntry::query()->count())->toBe(2)
        ->and(PaymentWebhookEvent::query()->count())->toBe(1)
        ->and(VerificationEvent::query()->where('event', 'order.paid')->count())->toBe(1)
        ->and(app(ReadLedgerBalances::class)()['balances'])->toBeTrue();
});

it('changes nothing when a redelivery arrives under a fresh event id', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);

    // A different charge id defeats the unique index. RecordPayment refuses on
    // its own account, which is the second of the three defences.
    foreach ([4242, 9999] as $chargeId) {
        $delivery = paystackDelivery($order, $chargeId);

        $this->postJson('/webhooks/paystack', $delivery['payload'], [
            'x-paystack-signature' => $delivery['signature'],
        ])->assertOk();
    }

    expect(PaymentWebhookEvent::query()->count())->toBe(2)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and(app(ReadLedgerBalances::class)()['balances'])->toBeTrue();
});

it('changes nothing when the customer returns from the payment page', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);

    $this->actingAs($it['account'], 'portal')
        ->withSession(['acting_party_id' => $it['party']->id])
        ->get("/portal/orders/{$order->id}/return?status=success&trxref={$order->reference}")
        ->assertRedirect("/portal/orders/{$order->id}");

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->paid_at)->toBeNull()
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('puts a paid visit into the console as a priority assignment', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it, 'location_verified', OrderUrgency::Express);

    app(RecordPayment::class)($order, $order->reference);
    $order->refresh();

    $supervisor = person(Role::Supervisor, 'Ada Supervisor');
    $officer = person(Role::Officer, 'Emeka Officer');

    $assignment = app(AssignVerificationVisit::class)($order, $officer, $supervisor);

    expect($assignment->kind)->toBe(Assignment::KIND_VISIT)
        ->and($assignment->structure_id)->toBe($order->structure_id)
        // Express sorts above standard, and both above a sweep.
        ->and($assignment->priority)->toBe(OrderUrgency::Express->priority())
        ->and($assignment->due_on?->toDateString())->toBe($order->due_by?->toDateString())
        ->and($order->refresh()->status)->toBe(OrderStatus::Assigned);

    // And it is on the screen a supervisor already uses.
    $this->actingAs($supervisor)
        ->get('/console/orders')
        ->assertOk()
        ->assertSee($order->reference);
});

it('does not let a paid visit block the cell from being swept', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);
    app(RecordPayment::class)($order, $order->reference);

    $supervisor = person(Role::Supervisor, 'Ada Supervisor');
    $officer = person(Role::Officer, 'Emeka Officer');

    app(AssignVerificationVisit::class)($order->refresh(), $officer, $supervisor);

    $cellId = $order->structure->grid_cell_id;

    // The ground was swept in order to enumerate this shop, so an open sweep is
    // already sitting on the cell. The visit went onto it anyway: the
    // exclusivity index is scoped to sweeps, and a paid visit must be givable
    // today whether or not somebody is already covering that ground.
    expect(Assignment::query()
        ->where('grid_cell_id', $cellId)
        ->whereNull('closed_at')
        ->where('kind', Assignment::KIND_SWEEP)
        ->count())->toBe(1);

    expect(Assignment::query()
        ->where('grid_cell_id', $cellId)
        ->whereNull('closed_at')
        ->where('kind', Assignment::KIND_VISIT)
        ->count())->toBe(1);

    // And two open sweeps on one cell are still refused, which is the invariant
    // the field platform has always had and which this change had to preserve.
    $sweeper = person(Role::Officer, 'Second Officer');

    expect(fn () => DB::transaction(fn () => Assignment::query()->create([
        'grid_cell_id' => $cellId,
        'kind' => Assignment::KIND_SWEEP,
        'user_id' => $sweeper->id,
        'assigned_by' => $supervisor->id,
        'assigned_at' => now(),
        'status' => AssignmentStatus::Assigned,
    ])))->toThrow(QueryException::class);
});

it('recognises the fee only when a supervisor accepts the work', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);

    app(RecordPayment::class)($order, $order->reference);
    $order->refresh();

    $supervisor = person(Role::Supervisor, 'Ada Supervisor');
    $officer = person(Role::Officer, 'Emeka Officer');

    app(AssignVerificationVisit::class)($order, $officer, $supervisor);

    // The officer attends and files it through the capture flow that already
    // exists. Nothing here knows the visit was paid for.
    officerFilesVisit($order->refresh(), $officer);

    // Still held, right up to the moment somebody accepts it.
    expect(app(ReadLedgerBalances::class)()['accounts'][LedgerAccount::VERIFICATION_INCOME])->toBe(0);

    $completed = app(CompleteOrder::class)($order->refresh(), $supervisor, OrderOutcome::Confirmed);

    expect($completed->status)->toBe(OrderStatus::Completed)
        ->and($completed->outcome)->toBe(OrderOutcome::Confirmed)
        ->and($completed->completed_by)->toBe($supervisor->id);

    $balances = app(ReadLedgerBalances::class)();

    expect($balances['balances'])->toBeTrue()
        ->and($balances['accounts'][LedgerAccount::CUSTOMER_FUNDS_HELD])->toBe(0)
        ->and($balances['accounts'][LedgerAccount::VERIFICATION_INCOME])->toBe(-$order->amount_minor)
        ->and($balances['accounts'][LedgerAccount::CASH])->toBe($order->amount_minor);
});

it('bills a negative finding, because the work was done', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);
    app(RecordPayment::class)($order, $order->reference);

    $supervisor = person(Role::Supervisor, 'Ada Supervisor');
    $officer = person(Role::Officer, 'Emeka Officer');

    app(AssignVerificationVisit::class)($order->refresh(), $officer, $supervisor);
    officerFilesVisit($order->refresh(), $officer);

    $completed = app(CompleteOrder::class)($order->refresh(), $supervisor, OrderOutcome::NotFound);

    expect($completed->status)->toBe(OrderStatus::Completed)
        ->and($completed->outcome->establishesTier())->toBeFalse()
        ->and(app(ReadLedgerBalances::class)()['accounts'][LedgerAccount::VERIFICATION_INCOME])
        ->toBe(-$order->amount_minor);
});

it('refunds an order we did not attend, without anybody asking', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);

    app(RecordPayment::class)($order, $order->reference);
    $order->refresh();

    // The date we promised comes and goes.
    $order->forceFill(['due_by' => now()->subDays(2)->toDateString()])->save();

    $this->artisan('orders:sweep-sla')->assertSuccessful();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Refunded)
        ->and($order->cancellation_reason)->toContain('working days we promised');

    $balances = app(ReadLedgerBalances::class)();

    expect($balances['balances'])->toBeTrue()
        ->and($balances['accounts'][LedgerAccount::CASH])->toBe(0)
        ->and($balances['accounts'][LedgerAccount::CUSTOMER_FUNDS_HELD])->toBe(0)
        ->and($balances['accounts'][LedgerAccount::VERIFICATION_INCOME])->toBe(0);

    // And running it again the next morning does not refund it twice.
    $this->artisan('orders:sweep-sla')->assertSuccessful();

    expect(LedgerEntry::query()->where('reason', LedgerEntry::REASON_REFUNDED)->count())->toBe(2);
});

it('lets the party buy again once a breached order is settled', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);
    app(RecordPayment::class)($order, $order->reference);
    $order->refresh()->forceFill(['due_by' => now()->subDay()->toDateString()])->save();

    $this->artisan('orders:sweep-sla')->assertSuccessful();

    // The partial unique index is scoped to live statuses, so the same tier is
    // purchasable the moment the first order is settled.
    expect(placeOrderFor($it)->status)->toBe(OrderStatus::AwaitingPayment);
});

it('resolves the zone from the register rather than from the request', function () {
    $it = buyerWithShop();

    $zone = app(ResolveServiceZone::class)($it['shop']->structure);

    expect($zone)->toBeInstanceOf(ServiceZone::class);

    // A structure whose ward never resolved is Zone B. Not knowing where
    // somebody is is not a reason to charge them less for the trip.
    $orphan = $it['shop']->structure->replicate();
    $orphan->ward_id = null;

    expect(app(ResolveServiceZone::class)($orphan))->toBe(ServiceZone::B);
});

it('charges more for a dedicated trip than for ground we already work', function () {
    $prices = app(ResolveVerificationPrice::class);

    $a = $prices('location_verified', OrderUrgency::Standard, ServiceZone::A);
    $b = $prices('location_verified', OrderUrgency::Standard, ServiceZone::B);

    expect($b->amount_minor)->toBeGreaterThan($a->amount_minor)
        // And express is a scheduling concession, not double the labour.
        ->and($prices('location_verified', OrderUrgency::Express, ServiceZone::A)->amount_minor)
        ->toBeLessThan($a->amount_minor * 2);
});

it('refuses to invent a price for something we do not sell', function () {
    expect(fn () => app(ResolveVerificationPrice::class)(
        'identity_verified',
        OrderUrgency::Express,
        ServiceZone::A,
    ))->toThrow(RuntimeException::class, 'no express price');
});

it('counts the promise in working days, around weekends and public holidays', function () {
    $days = app(WorkingDays::class);

    // Friday plus three working days lands on the Wednesday.
    expect($days->after(Carbon::parse('2026-10-30'), 3)->toDateString())->toBe('2026-11-04')
        // Christmas Eve plus two steps over Christmas, Boxing Day and the weekend.
        ->and($days->after(Carbon::parse('2026-12-24'), 2)->toDateString())->toBe('2026-12-29')
        // Independence Day is not a working day.
        ->and($days->isWorkingDay(Carbon::parse('2026-10-01')))->toBeFalse();
});

it('refuses a ledger movement that does not balance', function () {
    expect(fn () => app(PostTransaction::class)(
        [LedgerAccount::CASH => 1_000, LedgerAccount::VERIFICATION_INCOME => -900],
        LedgerEntry::REASON_PAYMENT_RECEIVED,
    ))->toThrow(RuntimeException::class, 'does not balance');

    expect(fn () => app(PostTransaction::class)(
        [LedgerAccount::CASH => 1_000],
        LedgerEntry::REASON_PAYMENT_RECEIVED,
    ))->toThrow(RuntimeException::class, 'at least two sides');

    expect(LedgerEntry::query()->count())->toBe(0);
});

it('will not let a ledger entry be changed or removed', function () {
    app(PostTransaction::class)(
        [LedgerAccount::CASH => 5_000, LedgerAccount::CUSTOMER_FUNDS_HELD => -5_000],
        LedgerEntry::REASON_PAYMENT_RECEIVED,
    );

    // Each attempt inside its own savepoint. The first one aborts the
    // transaction it runs in, and without the savepoint the second would fail
    // for that reason rather than for the reason being tested.
    expect(fn () => DB::transaction(fn () => DB::table('ledger_entries')->update(['amount_minor' => 1])))
        ->toThrow(QueryException::class, 'append only');

    expect(fn () => DB::transaction(fn () => DB::table('ledger_entries')->delete()))
        ->toThrow(QueryException::class, 'append only');

    expect(LedgerEntry::query()->count())->toBe(2);
});

it('hands the customer to the provider without marking anything paid', function () {
    Http::fake([
        'api.paystack.co/*' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123'],
        ]),
    ]);

    config()->set('services.paystack.secret', 'sk_test_marketplace');

    $it = buyerWithShop();
    $order = placeOrderFor($it);

    $this->actingAs($it['account'], 'portal')
        ->withSession(['acting_party_id' => $it['party']->id])
        ->post("/portal/orders/{$order->id}/pay")
        ->assertRedirect('https://checkout.paystack.com/abc123');

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and(LedgerEntry::query()->count())->toBe(0);

    Http::assertSent(function ($request): bool {
        // The amount charged is the amount stamped on the order, in kobo, and
        // is never recomputed at the till.
        return $request['amount'] === VerificationOrder::query()->value('amount_minor');
    });
});

it('shows a party their own order and nobody else theirs', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);

    $this->actingAs($it['account'], 'portal')
        ->withSession(['acting_party_id' => $it['party']->id])
        ->get("/portal/orders/{$order->id}")
        ->assertOk();

    $stranger = claimant('Tunde Balogun', '08039990003');

    $this->actingAs($stranger['account'], 'portal')
        ->withSession(['acting_party_id' => $stranger['party']->id])
        ->get("/portal/orders/{$order->id}")
        ->assertForbidden();
});

it('never uses the regulated term, anywhere in the codebase', function () {
    // A test rather than a code review note, because this is a legal exposure
    // rather than a style preference: the term is regulated in Nigeria and we
    // are not licensed for it. Funds are held and released.
    $roots = ['app', 'config', 'database', 'routes', 'resources/js', 'tests'];

    $found = [];

    foreach ($roots as $root) {
        $path = base_path($root);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (! in_array($file->getExtension(), ['php', 'ts', 'tsx', 'sql', 'json'], true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            // Assembled at runtime so this test file is not itself a hit, and
            // so nobody can find the word by grepping for it here either.
            $term = 'es'.'crow';

            if (stripos($contents, $term) !== false) {
                $found[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect($found)->toBe([]);
});
