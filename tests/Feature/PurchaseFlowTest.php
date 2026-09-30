<?php

declare(strict_types=1);

use App\Domain\Catalogue\Models\Product;
use App\Domain\Commerce\Actions\ManagePayouts;
use App\Domain\Commerce\Actions\ManagePurchase;
use App\Domain\Commerce\Actions\PlacePurchase;
use App\Domain\Commerce\Actions\ReadWallet;
use App\Domain\Commerce\Enums\PayChannel;
use App\Domain\Commerce\Enums\Protection;
use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\Payout;
use App\Domain\Commerce\Models\PayoutAccount;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Domain\Ledger\Actions\ReadLedgerBalances;
use App\Domain\Ledger\Actions\ReconcileWithProvider;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Party\Enums\PartyRole;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Party\Models\SignInCode;
use App\Domain\Registry\Actions\SetPublicationState;
use App\Domain\Registry\Enums\PublicationState;
use App\Domain\Registry\Models\EnterpriseObservation;
use App\Enums\Role;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/**
 * M2: buying from a business, with the money held until delivery.
 *
 * What is worth proving is where the money is at every step and who could
 * move it. Every test that moves money ends on the ledger balancing, because a
 * test that checks a status and not the ledger proves the wrong thing.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    directoryTaxonomy();
    config()->set('geoverify.commerce.delivery_fee_minor', 350_000);
    config()->set('geoverify.commerce.commission_basis_points', 250);
    config()->set('geoverify.commerce.minimum_payout_minor', 100_000);
    config()->set('services.paystack.secret', 'sk_test_marketplace');
});

/**
 * A claimed, published shop with two priced products, and a buyer who is
 * nobody at the shop.
 *
 * @param  ArrayObject<string, mixed>|null  $ground
 * @return array{seller: array<string, mixed>, rice: Product, oil: Product, buyer: PortalAccount}
 */
function shopWithCatalogue(?ArrayObject $ground = null): array
{
    $seller = buyerWithShop('Amaka Okafor', '08031110001', $ground);

    EnterpriseObservation::query()->where('enterprise_id', $seller['shop']->id)->update(['signage_observed' => true]);
    app(SetPublicationState::class)($seller['party'], $seller['account'], $seller['shop']->refresh(), PublicationState::OptedIn);

    $product = static fn (string $name, string $unit, int $naira, int $position): Product => Product::query()->create([
        'enterprise_id' => $seller['shop']->id,
        'party_id' => $seller['party']->id,
        'name' => $name,
        'unit' => $unit,
        'price_minor' => $naira * 100,
        'status' => Product::STATUS_ACTIVE,
        'position' => $position,
    ]);

    return [
        'seller' => $seller,
        'rice' => $product('Ofada rice', '50 kg bag', 78_000, 1),
        'oil' => $product('Palm oil', '25 litres', 55_500, 2),
        'buyer' => claimant('Tunde Bakare', '08032220002')['account'],
    ];
}

/**
 * @param  array{seller: array<string, mixed>, rice: Product, oil: Product, buyer: PortalAccount}  $it
 * @param  array<int, int>|null  $quantities
 */
function placePurchase(array $it, ?array $quantities = null): PurchaseOrder
{
    return app(PlacePurchase::class)(
        $it['buyer'],
        $it['seller']['shop'],
        $quantities ?? [$it['rice']->id => 2, $it['oil']->id => 1],
        ['name' => 'Tunde Bakare', 'phone' => '08032220002', 'address' => '14 Mississippi St., Maitama, Abuja'],
        Protection::None,
        PayChannel::Card,
    );
}

/**
 * @param  array<string, mixed>  $data
 * @return array{payload: array<string, mixed>, raw: string, signature: string}
 */
function signedEvent(string $event, array $data): array
{
    $payload = ['event' => $event, 'data' => $data];
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);

    return ['payload' => $payload, 'raw' => $raw, 'signature' => hash_hmac('sha512', $raw, 'sk_test_marketplace')];
}

/**
 * Posted as the provider posts it: the raw body the signature was made over,
 * through the real route, its middleware and its CSRF exemption.
 *
 * @param  array{payload: array<string, mixed>, raw: string, signature: string}  $event
 */
function deliver(array $event): int
{
    $request = Request::create('/webhooks/paystack', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => $event['signature'],
    ], $event['raw']);

    return app(Kernel::class)->handle($request)->getStatusCode();
}

function payFor(PurchaseOrder $order, int $chargeId = 9001, ?int $amount = null): void
{
    $status = deliver(signedEvent('charge.success', [
        'id' => $chargeId,
        'reference' => $order->reference,
        'amount' => $amount ?? $order->amount_minor,
        'currency' => 'NGN',
        'status' => 'success',
        'paid_at' => now()->toIso8601String(),
    ]));

    expect($status)->toBe(200);
}

/** @return array{accounts: array<string, int>, ledger: list<array<string, mixed>>, total: int, balances: bool} */
function ledger(): array
{
    return app(ReadLedgerBalances::class)();
}

it('prices an order from the catalogue and copies the lines as agreed', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);

    expect($order->reference)->toMatch('/^GV-\d{5,}$/')
        ->and($order->status)->toBe(PurchaseStatus::AwaitingPayment)
        ->and($order->seller_party_id)->toBe($it['seller']['party']->id)
        ->and($order->items_minor)->toBe((2 * 78_000 + 55_500) * 100)
        ->and($order->delivery_minor)->toBe(350_000)
        ->and($order->amount_minor)->toBe((211_500 + 3_500) * 100);

    // The merchant changes a price afterwards. The buyer's order does not move.
    $it['rice']->update(['price_minor' => 99_000_00]);

    $line = $order->items()->where('product_id', $it['rice']->id)->firstOrFail();

    expect($line->unit_price_minor)->toBe(78_000_00)
        ->and($line->name)->toBe('Ofada rice')
        ->and($line->line_minor)->toBe(156_000_00);

    // Nothing is owed until the provider says so.
    expect(ledger()['accounts'][LedgerAccount::CASH])->toBe(0);
});

it('sells nothing from a listing the directory does not publish', function () {
    $it = shopWithCatalogue();

    app(SetPublicationState::class)($it['seller']['party'], $it['seller']['account'], $it['seller']['shop']->refresh(), PublicationState::Withheld);

    expect(fn () => placePurchase($it))->toThrow(RuntimeException::class, 'not taking orders');
});

it('refuses a product that is not listed, whatever id the browser sends', function () {
    $it = shopWithCatalogue();
    $it['oil']->update(['status' => Product::STATUS_WITHDRAWN]);

    expect(fn () => placePurchase($it))->toThrow(RuntimeException::class, 'no longer listed');
});

it('will not let a business buy from itself', function () {
    $it = shopWithCatalogue();
    $it['buyer'] = $it['seller']['account'];

    expect(fn () => placePurchase($it))->toThrow(RuntimeException::class, 'cannot buy from it');
});

it('offers inspection and site visits only once they have a price', function () {
    $it = shopWithCatalogue();
    config()->set('geoverify.commerce.inspection_fee_minor', null);

    $place = static fn (): PurchaseOrder => app(PlacePurchase::class)(
        $it['buyer'],
        $it['seller']['shop'],
        [$it['rice']->id => 1],
        ['name' => 'Tunde', 'phone' => '0803', 'address' => 'Maitama'],
        Protection::Inspection,
        PayChannel::Card,
    );

    expect($place)->toThrow(RuntimeException::class, 'not available yet');

    config()->set('geoverify.commerce.inspection_fee_minor', 250_000);

    expect($place()->service_fee_minor)->toBe(250_000);
});

it('holds the money when the signed webhook says it arrived, once', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);

    payFor($order);
    payFor($order); // The provider redelivers.

    $order->refresh();

    expect($order->status)->toBe(PurchaseStatus::Held)
        ->and($order->paid_at)->not->toBeNull()
        ->and(ledger()['accounts'][LedgerAccount::CASH])->toBe($order->amount_minor)
        ->and(ledger()['accounts'][LedgerAccount::BUYER_FUNDS_HELD])->toBe(-$order->amount_minor)
        ->and(ledger()['balances'])->toBeTrue()
        ->and(app(ReadWallet::class)($it['seller']['party']->id))
        ->toBe(['heldMinor' => $order->amount_minor, 'availableMinor' => 0, 'inTransitMinor' => 0]);
});

it('does not hold a payment for the wrong amount against the order', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);

    payFor($order, amount: 100);

    expect($order->refresh()->status)->toBe(PurchaseStatus::AwaitingPayment)
        ->and(ledger()['accounts'][LedgerAccount::CASH])->toBe(0);
});

it('marks nothing paid on an unsigned delivery', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);

    $event = signedEvent('charge.success', ['id' => 1, 'reference' => $order->reference, 'amount' => $order->amount_minor]);
    $event['signature'] = 'forged';

    expect(deliver($event))->toBe(401);

    expect($order->refresh()->status)->toBe(PurchaseStatus::AwaitingPayment);
});

it('pays the merchant, less commission on the goods, when the buyer confirms', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);
    payFor($order);

    $purchases = app(ManagePurchase::class);
    $purchases->dispatch($order, $it['seller']['membership']);
    $purchases->confirm($order, $it['buyer']);

    $order->refresh();

    $commission = intdiv($order->items_minor * 250, 10_000);
    $net = $order->items_minor + $order->delivery_minor - $commission;

    expect($order->status)->toBe(PurchaseStatus::Released)
        ->and($order->released_by)->toBe(PurchaseOrder::RELEASED_BY_BUYER)
        ->and($order->commission_minor)->toBe($commission)
        ->and(ledger()['accounts'][LedgerAccount::BUYER_FUNDS_HELD])->toBe(0)
        ->and(ledger()['accounts'][LedgerAccount::MERCHANT_BALANCES])->toBe(-$net)
        ->and(ledger()['accounts'][LedgerAccount::COMMERCE_INCOME])->toBe(-$commission)
        ->and(ledger()['balances'])->toBeTrue()
        ->and(app(ReadWallet::class)($it['seller']['party']->id)['availableMinor'])->toBe($net);

    // The timeline is the event log, in order.
    expect($order->events()->pluck('event')->all())
        ->toBe(['purchase.placed', 'purchase.paid', 'purchase.dispatched', 'purchase.confirmed']);
});

it('lets only the buyer confirm, and only somebody at the shop send', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);
    payFor($order);

    $stranger = claimant('Somebody Else', '08035550005');

    expect(fn () => app(ManagePurchase::class)->confirm($order, $stranger['account']))
        ->toThrow(RuntimeException::class, 'not yours')
        ->and(fn () => app(ManagePurchase::class)->dispatch($order, $stranger['membership']))
        ->toThrow(RuntimeException::class, 'not for your business');

    $it['seller']['membership']->update(['role' => PartyRole::Viewer]);

    expect(fn () => app(ManagePurchase::class)->dispatch($order, $it['seller']['membership']->refresh()))
        ->toThrow(RuntimeException::class, 'not send them');
});

it('holds a disputed order until an admin rules, and refunds on a ruling for the buyer', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);
    payFor($order);

    $purchases = app(ManagePurchase::class);
    $purchases->dispatch($order, $it['seller']['membership']);
    $purchases->raiseIssue($order, $it['buyer'], 'Two bags arrived torn and half empty.');

    // A supervisor cannot rule, and the window cannot release it. (The buyer
    // could still confirm, which is how an issue sorted out between the two
    // of them is closed: it is the buyer's money to let go of.)
    expect(fn () => $purchases->rule($order, person(Role::Supervisor), true, 'Photos show torn bags.'))
        ->toThrow(RuntimeException::class, 'Only an admin');

    $this->travel(30)->days();
    $this->artisan('orders:release-delivered')->assertSuccessful();
    expect($order->refresh()->status)->toBe(PurchaseStatus::Disputed);

    $purchases->rule($order, person(Role::Admin), true, 'Photos show torn bags.');

    expect($order->refresh()->status)->toBe(PurchaseStatus::Refunded)
        ->and(ledger()['accounts'][LedgerAccount::BUYER_FUNDS_HELD])->toBe(0)
        ->and(ledger()['accounts'][LedgerAccount::CASH])->toBe(0)
        ->and(ledger()['accounts'][LedgerAccount::MERCHANT_BALANCES])->toBe(0)
        ->and(ledger()['balances'])->toBeTrue();
});

it('releases to the merchant on a ruling in their favour', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);
    payFor($order);

    app(ManagePurchase::class)->raiseIssue($order, $it['buyer'], 'It has not arrived yet, where is it?');
    app(ManagePurchase::class)->rule($order, person(Role::Admin), false, 'Rider log shows delivery at 14:02.');

    expect($order->refresh()->status)->toBe(PurchaseStatus::Released)
        ->and($order->released_by)->toBe(PurchaseOrder::RELEASED_BY_RULING)
        ->and(ledger()['balances'])->toBeTrue();
});

it('pays a merchant whose buyer never confirmed, once the window has closed', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);
    payFor($order);
    app(ManagePurchase::class)->dispatch($order, $it['seller']['membership']);

    $this->travel(6)->days();
    $this->artisan('orders:release-delivered')->assertSuccessful();
    expect($order->refresh()->status)->toBe(PurchaseStatus::Dispatched);

    $this->travel(2)->days();
    $this->artisan('orders:release-delivered')->assertSuccessful();

    expect($order->refresh()->status)->toBe(PurchaseStatus::Released)
        ->and($order->released_by)->toBe(PurchaseOrder::RELEASED_BY_WINDOW);
});

it('lets a buyer abandon an unpaid order and nothing else', function () {
    $it = shopWithCatalogue();
    $unpaid = placePurchase($it);
    $paid = placePurchase($it);
    payFor($paid, 9002);

    app(ManagePurchase::class)->cancel($unpaid, $it['buyer']);

    expect($unpaid->refresh()->status)->toBe(PurchaseStatus::Cancelled)
        ->and(fn () => app(ManagePurchase::class)->cancel($paid, $it['buyer']))->toThrow(RuntimeException::class);
});

/**
 * A merchant with a released order and a saved bank account.
 *
 * @return array{seller: array<string, mixed>, rice: Product, oil: Product, buyer: PortalAccount, order: PurchaseOrder}
 */
function merchantWithBalance(): array
{
    $it = shopWithCatalogue();
    $order = placePurchase($it);
    payFor($order);
    app(ManagePurchase::class)->confirm($order, $it['buyer']);

    PayoutAccount::query()->create([
        'party_id' => $it['seller']['party']->id,
        'bank_code' => '058',
        'bank_name' => 'Guaranty Trust Bank',
        'account_last4' => '4821',
        'account_name' => 'AMAKA OKAFOR',
        'recipient_code' => 'RCP_test123',
        'status' => PayoutAccount::STATUS_ACTIVE,
        'added_by' => $it['seller']['account']->id,
    ]);

    return $it + ['order' => $order->refresh()];
}

it('reserves a withdrawal when asked, and lets the money go only on the transfer webhook', function () {
    Http::fake(['api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_1']])]);

    $it = merchantWithBalance();
    $party = $it['seller']['party']->id;
    $before = app(ReadWallet::class)($party)['availableMinor'];

    $payout = app(ManagePayouts::class)->request($it['seller']['membership'], 150_000_00);

    expect($payout->status)->toBe(Payout::STATUS_REQUESTED)
        ->and($payout->transfer_code)->toBe('TRF_1')
        ->and(app(ReadWallet::class)($party))->toBe([
            'heldMinor' => 0,
            'availableMinor' => $before - 150_000_00,
            'inTransitMinor' => 150_000_00,
        ])
        ->and(ledger()['accounts'][LedgerAccount::CASH])->toBe($it['order']->amount_minor);

    expect(deliver(signedEvent('transfer.success', ['id' => 77, 'reference' => $payout->reference, 'amount' => 150_000_00])))->toBe(200);

    expect($payout->refresh()->status)->toBe(Payout::STATUS_PAID)
        ->and(app(ReadWallet::class)($party)['inTransitMinor'])->toBe(0)
        ->and(ledger()['accounts'][LedgerAccount::CASH])->toBe($it['order']->amount_minor - 150_000_00)
        ->and(ledger()['balances'])->toBeTrue();
});

it('puts a failed transfer back in the balance', function () {
    Http::fake(['api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_2']])]);

    $it = merchantWithBalance();
    $party = $it['seller']['party']->id;
    $before = app(ReadWallet::class)($party)['availableMinor'];

    $payout = app(ManagePayouts::class)->request($it['seller']['membership'], 100_000_00);

    expect(deliver(signedEvent('transfer.failed', ['id' => 78, 'reference' => $payout->reference, 'reason' => 'Account closed'])))->toBe(200);

    expect($payout->refresh()->status)->toBe(Payout::STATUS_RETURNED)
        ->and($payout->failure_reason)->toBe('Account closed')
        ->and(app(ReadWallet::class)($party)['availableMinor'])->toBe($before)
        ->and(ledger()['balances'])->toBeTrue();
});

it('will not pay out more than is available, or to anybody but an owner', function () {
    Http::fake(['api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_3']])]);

    $it = merchantWithBalance();
    $available = app(ReadWallet::class)($it['seller']['party']->id)['availableMinor'];

    expect(fn () => app(ManagePayouts::class)->request($it['seller']['membership'], $available + 1))
        ->toThrow(RuntimeException::class, 'more than your available balance');

    $it['seller']['membership']->update(['role' => PartyRole::Manager]);

    expect(fn () => app(ManagePayouts::class)->request($it['seller']['membership']->refresh(), 100_000_00))
        ->toThrow(RuntimeException::class, 'Only an owner');

    expect(Payout::query()->count())->toBe(0);
});

it('returns a withdrawal the provider refused outright', function () {
    Http::fake(['api.paystack.co/transfer' => Http::response(['status' => false, 'message' => 'Insufficient balance'], 400)]);

    $it = merchantWithBalance();
    $before = app(ReadWallet::class)($it['seller']['party']->id)['availableMinor'];

    $payout = app(ManagePayouts::class)->request($it['seller']['membership'], 100_000_00);

    expect($payout->status)->toBe(Payout::STATUS_RETURNED)
        ->and(app(ReadWallet::class)($it['seller']['party']->id)['availableMinor'])->toBe($before)
        ->and(ledger()['balances'])->toBeTrue();
});

it('reconciles a product order against the provider as it does a verification order', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);
    payFor($order);

    Http::fake(['api.paystack.co/transaction*' => Http::response(['status' => true, 'data' => [
        ['reference' => $order->reference, 'amount' => $order->amount_minor, 'paid_at' => now()->toIso8601String()],
    ]])]);

    $report = app(ReconcileWithProvider::class)(now()->subDay(), now()->addDay());

    expect($report['drift_minor'])->toBe(0)
        ->and($report['missing_from_ledger'])->toBe([])
        ->and($report['ledger_count'])->toBe(1);
});

it('checks out over HTTP and hands the buyer to the provider, charging what the server priced', function () {
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.com/xyz'],
    ])]);

    $it = shopWithCatalogue();

    $this->actingAs($it['buyer'], 'portal')
        ->get("/portal/checkout/{$it['seller']['shop']->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/Checkout')->has('products', 2));

    $this->actingAs($it['buyer'], 'portal')
        ->post("/portal/checkout/{$it['seller']['shop']->id}", [
            'items' => [['id' => $it['rice']->id, 'quantity' => 2], ['id' => $it['oil']->id, 'quantity' => 1]],
            'delivery' => ['name' => 'Tunde Bakare', 'phone' => '08032220002', 'address' => '14 Mississippi St., Maitama'],
            'protection' => 'none',
            'channel' => 'bank_transfer',
            // Whatever the browser claims, it is not a price.
            'amount' => 1,
        ])
        ->assertRedirect('https://checkout.paystack.com/xyz');

    $order = PurchaseOrder::query()->sole();

    Http::assertSent(static fn ($request): bool => $request['amount'] === $order->amount_minor
        && $request['reference'] === $order->reference
        && $request['channels'] === ['bank_transfer']);

    expect($order->amount_minor)->toBe((211_500 + 3_500) * 100);
});

it('sends somebody signed out back to checkout once they have signed in', function () {
    $it = shopWithCatalogue();

    $this->get("/portal/checkout/{$it['seller']['shop']->id}")->assertRedirect('/portal/sign-in');

    expect(session('portal.intended'))->toEndWith("/portal/checkout/{$it['seller']['shop']->id}");
});

/** A sign-in code that will verify, as RequestSignInCode would have stored it. */
function knownCode(PortalAccount $account): string
{
    SignInCode::query()->create([
        'phone' => $account->phone,
        'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(10),
        'attempts' => 0,
    ]);

    return '123456';
}

it('never sends a portal sign-in to a page the staff login remembered', function () {
    // Somebody opened the console while signed out: Laravel remembers /console
    // for the staff login. Signing in to the portal must not go there.
    $account = claimant('Tunde Bakare', '08032220002')['account'];

    $this->withSession(['portal.phone' => $account->phone, 'portal.intent' => 'sign-in', 'url.intended' => url('/console')])
        ->post('/portal/verify', ['code' => knownCode($account)])
        ->assertRedirect(route('portal.dashboard'));
});

it('returns a portal sign-in to the portal page it was sent from', function () {
    $it = shopWithCatalogue();
    $checkout = url("/portal/checkout/{$it['seller']['shop']->id}");

    $this->get($checkout)->assertRedirect('/portal/sign-in');

    $this->withSession(['portal.phone' => $it['buyer']->phone, 'portal.intent' => 'sign-in', 'portal.intended' => $checkout])
        ->post('/portal/verify', ['code' => knownCode($it['buyer'])])
        ->assertRedirect($checkout);
});

it('shows an order only to its buyer and its seller', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);
    payFor($order);
    $stranger = claimant('Somebody Else', '08035550005');

    $this->actingAs($it['buyer'], 'portal')->get("/portal/purchases/{$order->id}")->assertOk();
    $this->actingAs($stranger['account'], 'portal')->get("/portal/purchases/{$order->id}")->assertNotFound();

    $this->actingAs($it['seller']['account'], 'portal')
        ->get("/portal/sales/{$order->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/Sale')->where('can.dispatch', true)->where('order.money.netNaira', intdiv($order->netPayoutMinor(), 100)));
    $this->actingAs($stranger['account'], 'portal')->get("/portal/sales/{$order->id}")->assertNotFound();

    $this->actingAs($it['seller']['account'], 'portal')
        ->get('/portal/orders')
        ->assertInertia(fn ($page) => $page->has('sales', 1)->where('sales.0.reference', $order->reference));
});

it('walks an order from dispatch to release through the buttons', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);
    payFor($order);

    $this->actingAs($it['seller']['account'], 'portal')->post("/portal/sales/{$order->id}/dispatch")->assertSessionHasNoErrors();
    $this->actingAs($it['buyer'], 'portal')->post("/portal/purchases/{$order->id}/confirm")->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(PurchaseStatus::Released);

    $this->actingAs($it['seller']['account'], 'portal')
        ->get('/portal/wallet')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/Wallet')
            ->where('availableNaira', intdiv($order->netPayoutMinor(), 100))
            ->where('heldNaira', 0));
});

it('lets an admin rule on a dispute and nobody else reach the page', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);
    payFor($order);
    app(ManagePurchase::class)->raiseIssue($order, $it['buyer'], 'The palm oil keg was leaking badly.');

    $this->actingAs(person(Role::Supervisor))->get('/admin/disputes')->assertForbidden();

    $admin = person(Role::Admin);

    $this->actingAs($admin)
        ->get('/admin/disputes')
        ->assertInertia(fn ($page) => $page->component('admin/Disputes')->has('disputes', 1));

    $this->actingAs($admin)
        ->post("/admin/disputes/{$order->id}", ['for' => 'buyer', 'note' => 'Photos show the leak.'])
        ->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(PurchaseStatus::Refunded);
});
