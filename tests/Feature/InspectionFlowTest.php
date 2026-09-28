<?php

declare(strict_types=1);

use App\Domain\Commerce\Actions\ManageInspections;
use App\Domain\Commerce\Actions\ManagePurchase;
use App\Domain\Commerce\Actions\PlacePurchase;
use App\Domain\Commerce\Actions\PresentPurchase;
use App\Domain\Commerce\Enums\PayChannel;
use App\Domain\Commerce\Enums\Protection;
use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\Inspection;
use App\Domain\Field\Models\Assignment;
use App\Domain\Field\Models\FieldMessage;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Media\Actions\StorePhotograph;
use App\Domain\Media\Models\Media;
use App\Domain\Registry\Actions\SearchDirectory;
use App\Domain\Registry\Models\Structure;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * M3: an agent checks the goods, the buyer approves, and only then are they sent.
 *
 * The rules worth proving are the ordering ones (no dispatch before approval,
 * no report before arrival and a photograph) and the exposure one: the agent's
 * photographs reach this order's two parties and nobody else.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    directoryTaxonomy();
    Storage::fake('local');
    config()->set('geoverify.commerce.delivery_fee_minor', 350_000);
    config()->set('geoverify.commerce.commission_basis_points', 250);
    config()->set('geoverify.commerce.inspection_fee_minor', 250_000);
    config()->set('geoverify.commerce.visit_fee_minor', 500_000);
    config()->set('services.paystack.secret', 'sk_test_marketplace');
});

/** @return array<string, mixed> */
function inspectedOrder(Protection $protection = Protection::Inspection): array
{
    $it = shopWithCatalogue();

    $order = app(PlacePurchase::class)(
        $it['buyer'],
        $it['seller']['shop'],
        [$it['rice']->id => 2],
        ['name' => 'Tunde Bakare', 'phone' => '08032220002', 'address' => '14 Mississippi St., Maitama'],
        $protection,
        PayChannel::Card,
        $protection === Protection::SiteVisit ? now()->addDay() : null,
        $protection === Protection::SiteVisit ? 'with_me' : null,
    );

    return $it + ['order' => $order];
}

/**
 * @param  array<string, mixed>  $it
 * @return array{agent: User, supervisor: User, inspection: Inspection}
 */
function sendAgent(array $it): array
{
    $supervisor = person(Role::Supervisor, 'Hauwa Ibrahim');
    $agent = person(Role::Officer, 'Musa Danjuma');
    $inspection = Inspection::query()->where('purchase_order_id', $it['order']->id)->sole();

    app(ManageInspections::class)->assign($inspection, $agent, $supervisor);

    return ['agent' => $agent, 'supervisor' => $supervisor, 'inspection' => $inspection->refresh()];
}

/**
 * The agent stands at the premises, photographs the goods, answers every check.
 * Through the actions the three field endpoints call; the endpoints themselves
 * are exercised in the tests below.
 */
function agentReports(Inspection $inspection, User $agent, bool $allPass = true): void
{
    /** @var object{lon: float, lat: float} $centre */
    $centre = DB::selectOne('SELECT ST_X(s.centroid::geometry) AS lon, ST_Y(s.centroid::geometry) AS lat
        FROM inspections i JOIN purchase_orders po ON po.id = i.purchase_order_id
        JOIN enterprises e ON e.id = po.enterprise_id JOIN structures s ON s.id = e.structure_id WHERE i.id = ?', [$inspection->id]);

    $manage = app(ManageInspections::class);
    $manage->arrive($inspection, $agent, (float) $centre->lon, (float) $centre->lat, 4.0);

    app(StorePhotograph::class)->store(UploadedFile::fake()->image('rice.jpg', 800, 600), $inspection, Media::KIND_INSPECTION, $agent, (string) Str::uuid7());

    $answers = [];
    foreach (array_keys(Inspection::CHECKLISTS[$inspection->kind]) as $key) {
        $answers[$key] = ['passed' => $allPass, 'detail' => $key === 'weight' ? '50.2 kg, 49.8 kg' : null];
    }

    $manage->submit($inspection->refresh(), $agent, (string) Str::uuid7(), $answers, 'Bags sealed, weighed on the shop scale.');
}

it('asks for an inspection with the order, and queues it only once paid', function () {
    $it = inspectedOrder();
    $supervisor = person(Role::Supervisor);

    expect(Inspection::query()->where('purchase_order_id', $it['order']->id)->sole()->status)->toBe(Inspection::REQUESTED)
        ->and($it['order']->service_fee_minor)->toBe(250_000);

    $this->actingAs($supervisor)->get('/console/inspections')->assertInertia(fn ($page) => $page->has('jobs', 0));

    payFor($it['order']);

    $this->actingAs($supervisor)->get('/console/inspections')->assertInertia(fn ($page) => $page->has('jobs', 1)->where('jobs.0.status', 'requested'));
});

it('sends the agent a job on their device, apart from their cells', function () {
    $it = inspectedOrder();
    payFor($it['order']);
    ['agent' => $agent, 'inspection' => $inspection] = sendAgent($it);

    $assignment = Assignment::query()->findOrFail($inspection->assignment_id);

    expect($assignment->kind)->toBe(Assignment::KIND_INSPECTION)
        ->and($assignment->structure_id)->toBe($it['seller']['shop']->structure_id)
        ->and(FieldMessage::query()->where('officer_id', $agent->id)->where('body', 'like', 'Product inspection at%')->exists())->toBeTrue();

    $this->actingAs($agent)->get('/field')->assertInertia(fn ($page) => $page
        ->has('day.jobs', 1)
        ->where('day.jobs.0.id', $inspection->id)
        ->where('day.cells.total', 0));

    $this->actingAs($agent)->get("/field/jobs/{$inspection->id}")->assertOk();
    $this->actingAs(person(Role::Officer))->get("/field/jobs/{$inspection->id}")->assertNotFound();
});

it('holds the goods until the buyer approves the report, then runs as any order', function () {
    $it = inspectedOrder();
    payFor($it['order']);
    ['agent' => $agent, 'inspection' => $inspection] = sendAgent($it);

    expect(fn () => app(ManagePurchase::class)->dispatch($it['order'], $it['seller']['membership']))
        ->toThrow(RuntimeException::class, 'approve the inspection report');

    agentReports($inspection, $agent);

    $report = app(PresentPurchase::class)($it['order']->refresh())['inspection'];

    expect($report['status'])->toBe(Inspection::SUBMITTED)
        ->and($report['matchedPremises'])->toBeTrue()
        ->and($report['photos'])->toHaveCount(1)
        ->and(array_column($report['checklist'], 'detail', 'key')['weight'])->toBe('50.2 kg, 49.8 kg');

    $this->actingAs($it['seller']['account'], 'portal')->get("/portal/sales/{$it['order']->id}")
        ->assertInertia(fn ($page) => $page->where('can.dispatch', false));

    $this->actingAs($it['buyer'], 'portal')->post("/portal/purchases/{$it['order']->id}/inspection", ['approve' => true])->assertSessionHasNoErrors();

    app(ManagePurchase::class)->dispatch($it['order'], $it['seller']['membership']);
    app(ManagePurchase::class)->confirm($it['order'], $it['buyer']);

    $order = $it['order']->refresh();

    // The fee the buyer paid for the agent is ours, never the merchant's.
    expect($order->status)->toBe(PurchaseStatus::Released)
        ->and(ledger()['accounts'][LedgerAccount::COMMERCE_INCOME])->toBe(-($order->commission_minor + 250_000))
        ->and(ledger()['balances'])->toBeTrue()
        ->and($order->events()->pluck('event')->all())->toContain('purchase.inspection_assigned', 'purchase.inspection_submitted', 'purchase.inspection_approved');
});

it('turns a rejected report into an issue, with the money still held', function () {
    $it = inspectedOrder();
    payFor($it['order']);
    ['agent' => $agent, 'inspection' => $inspection] = sendAgent($it);
    agentReports($inspection, $agent, allPass: false);

    $this->actingAs($it['buyer'], 'portal')
        ->post("/portal/purchases/{$it['order']->id}/inspection", ['approve' => false, 'note' => 'Bags are the wrong size.'])
        ->assertSessionHasNoErrors();

    expect($inspection->refresh()->status)->toBe(Inspection::REJECTED)
        ->and($it['order']->refresh()->status)->toBe(PurchaseStatus::Disputed)
        ->and(ledger()['accounts'][LedgerAccount::BUYER_FUNDS_HELD])->toBe(-$it['order']->amount_minor);
});

it('refuses a report before arrival, without a photograph, or with the wrong checks', function () {
    $it = inspectedOrder();
    payFor($it['order']);
    ['agent' => $agent, 'inspection' => $inspection] = sendAgent($it);
    $manage = app(ManageInspections::class);
    $answers = array_map(static fn (): array => ['passed' => true], Inspection::CHECKLISTS[Inspection::KIND_INSPECTION]);

    expect(fn () => $manage->submit($inspection, $agent, (string) Str::uuid7(), $answers, null))->toThrow(RuntimeException::class, 'arrival');

    $manage->arrive($inspection, $agent, 7.46, 9.05, 5);

    expect(fn () => $manage->submit($inspection->refresh(), $agent, (string) Str::uuid7(), $answers, null))->toThrow(RuntimeException::class, 'photograph')
        ->and(fn () => $manage->submit($inspection, person(Role::Officer), (string) Str::uuid7(), $answers, null))->toThrow(RuntimeException::class, 'not yours');

    $this->actingAs($agent)->post("/api/field/jobs/{$inspection->id}/photos", ['client_uuid' => (string) Str::uuid7(), 'photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();

    expect(fn () => $manage->submit($inspection->refresh(), $agent, (string) Str::uuid7(), ['quantity' => ['passed' => true]], null))->toThrow(RuntimeException::class, 'every check');

    // Filed twice from the handset: one report.
    $uuid = (string) Str::uuid7();
    $manage->submit($inspection, $agent, $uuid, $answers, null);
    $manage->submit($inspection->refresh(), $agent, $uuid, $answers, null);

    expect($inspection->refresh()->status)->toBe(Inspection::SUBMITTED);
});

it('reports only whether the agent was at the premises, never where', function () {
    $it = inspectedOrder();
    payFor($it['order']);
    ['agent' => $agent, 'inspection' => $inspection] = sendAgent($it);

    // Two kilometres off.
    app(ManageInspections::class)->arrive($inspection, $agent, 7.48, 9.05, 5);

    $report = app(PresentPurchase::class)($it['order']->refresh())['inspection'];
    $encoded = json_encode($report, JSON_THROW_ON_ERROR);

    expect($report['matchedPremises'])->toBeFalse()
        ->and($encoded)->not->toContain('7.48')
        ->and($encoded)->not->toContain('arrival_position');
});

it('arranges a site visit with a time and who goes, and asks for both', function () {
    $it = inspectedOrder(Protection::SiteVisit);

    $visit = Inspection::query()->where('purchase_order_id', $it['order']->id)->sole();

    expect($visit->kind)->toBe(Inspection::KIND_SITE_VISIT)
        ->and($visit->visit_mode)->toBe('with_me')
        ->and($visit->requested_for)->not->toBeNull();

    expect(fn () => app(PlacePurchase::class)($it['buyer'], $it['seller']['shop'], [$it['rice']->id => 1],
        ['name' => 'A', 'phone' => '1', 'address' => 'B'], Protection::SiteVisit, PayChannel::Card, now()->subHour(), 'for_me'))
        ->toThrow(RuntimeException::class, 'has not passed');
});

it('shows the agent photographs to the two parties and on no other surface', function () {
    $it = inspectedOrder();
    payFor($it['order']);
    ['agent' => $agent, 'inspection' => $inspection] = sendAgent($it);
    agentReports($inspection, $agent);

    $photo = $inspection->photos()->sole();

    // The buyer and the merchant see it on the order.
    $this->actingAs($it['buyer'], 'portal')->get("/portal/purchases/{$it['order']->id}")
        ->assertInertia(fn ($page) => $page->has('order.inspection.photos', 1));
    $this->actingAs($it['seller']['account'], 'portal')->get("/portal/sales/{$it['order']->id}")
        ->assertInertia(fn ($page) => $page->has('order.inspection.photos', 1));

    // A stranger cannot open the order at all.
    $stranger = claimant('Somebody Else', '08035550005');
    $this->actingAs($stranger['account'], 'portal')->get("/portal/purchases/{$it['order']->id}")->assertNotFound();

    // Not on the listing, its products, the list or the map.
    $directory = app(SearchDirectory::class);
    $listing = $directory->listing($it['seller']['shop']);
    $everywhere = json_encode([
        $listing,
        $directory->productsFor($listing ?? []),
        $directory->run(),
        $directory->mapFor([]),
    ], JSON_THROW_ON_ERROR);

    expect($everywhere)->not->toContain($photo->disk_path)
        ->and($everywhere)->not->toContain((string) $photo->client_uuid);

    // Attached to the inspection, never to the building, so nothing that
    // gathers a building's photographs can reach it.
    expect($photo->mediable_type)->toBe((new Inspection)->getMorphClass())
        ->and(DB::table('media')->where('mediable_type', (new Structure)->getMorphClass())->where('kind', 'inspection')->exists())->toBeFalse();
});

it('finds the cell of a business that registered itself, and says so when there is none', function () {
    $it = inspectedOrder();
    payFor($it['order']);
    $structureId = $it['seller']['shop']->structure_id;
    $cellId = DB::table('structures')->where('id', $structureId)->value('grid_cell_id');

    // Reshaped into what a self-registered business is: no officer, no cell,
    // registered by its own party. The register's check constraint insists.
    DB::table('structures')->where('id', $structureId)->update([
        'origin' => 'self_registered',
        'captured_by' => null,
        'grid_cell_id' => null,
        'registered_by_party_id' => $it['seller']['party']->id,
    ]);

    ['inspection' => $inspection] = sendAgent($it);

    expect(Assignment::query()->findOrFail($inspection->assignment_id)->grid_cell_id)->toBe($cellId);

    // Outside every mandate: moved a long way off.
    DB::update('UPDATE structures SET centroid = ST_SetSRID(ST_MakePoint(3.38, 6.52), 4326)::geography WHERE id = ?', [$structureId]);
    $inspection->update(['status' => Inspection::REQUESTED]);

    expect(fn () => app(ManageInspections::class)->assign($inspection->refresh(), person(Role::Officer), person(Role::Supervisor)))
        ->toThrow(RuntimeException::class, 'outside every area');
});
