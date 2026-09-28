<?php

declare(strict_types=1);

use App\Domain\Catalogue\Actions\ManageBusinessProfile;
use App\Domain\Commerce\Actions\ManagePurchase;
use App\Domain\Commerce\Actions\ManageReviews;
use App\Domain\Commerce\Actions\PlacePurchase;
use App\Domain\Commerce\Enums\PayChannel;
use App\Domain\Commerce\Enums\Protection;
use App\Domain\Commerce\Models\Review;
use App\Domain\Party\Enums\PartyRole;
use App\Domain\Registry\Actions\SearchDirectory;
use App\Enums\Role;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M4: the directory's search and map.
 *
 * Each filter is an owner statement, so each is proved twice: that it finds
 * the published business it should, and that it never says anything about a
 * business that has published nothing.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    directoryTaxonomy();
    config()->set('geoverify.commerce.delivery_fee_minor', 350_000);
    config()->set('services.paystack.secret', 'sk_test_marketplace');
    // A Monday, mid morning in Lagos.
    $this->travelTo(Carbon::parse('2026-09-28 10:30', 'Africa/Lagos'));
});

/**
 * @param  array<string, mixed>  $it
 * @param  array<string, array{opens: string, closes: string}|null>  $hours
 */
function openHours(array $it, array $hours, ?bool $delivers = null, ?string $address = null): void
{
    app(ManageBusinessProfile::class)->save($it['seller']['shop'], $it['seller']['membership'], $hours, $delivers, $address);
}

/**
 * @param  list<array<string, mixed>>  $results
 * @return array<string, mixed>|null
 */
function rowFor(array $results, int $id): ?array
{
    return collect($results)->firstWhere('id', $id);
}

it('finds a business open now by its owner hours, and says nothing of one that published none', function () {
    $ground = sweptGround();
    $it = shopWithCatalogue($ground);
    $reduced = signpostedShop('Unclaimed Kiosk', $ground);

    openHours($it, ['mon' => ['opens' => '08:00', 'closes' => '18:00']]);

    $open = app(SearchDirectory::class)->run(openNow: true)['results'];

    expect(collect($open)->pluck('id')->all())->toBe([$it['seller']['shop']->id])
        ->and(rowFor($open, $it['seller']['shop']->id)['openNow'])->toBeTrue()
        ->and(rowFor(app(SearchDirectory::class)->run()['results'], $reduced->id)['openNow'])->toBeNull();

    // Evening: closed, and so not in the filter.
    $this->travelTo(Carbon::parse('2026-09-28 19:00', 'Africa/Lagos'));

    expect(app(SearchDirectory::class)->run(openNow: true)['results'])->toBe([]);
});

it('refuses hours that close before they open', function () {
    $it = shopWithCatalogue();

    expect(fn () => openHours($it, ['mon' => ['opens' => '18:00', 'closes' => '08:00']]))->toThrow(RuntimeException::class, 'closes after it opens')
        ->and(fn () => openHours($it, ['tue' => ['opens' => '8am', 'closes' => '6pm']]))->toThrow(RuntimeException::class, 'hours and minutes');
});

it('filters by selling on GeoVerify and by delivery, and offers inspection only once it has a price', function () {
    $it = shopWithCatalogue();
    $search = app(SearchDirectory::class);

    expect(collect($search->run(payable: true)['results'])->pluck('id')->all())->toBe([$it['seller']['shop']->id])
        ->and($search->run(delivers: true)['results'])->toBe([]);

    openHours($it, [], delivers: true);

    expect(collect($search->run(delivers: true)['results'])->pluck('id')->all())->toBe([$it['seller']['shop']->id])
        ->and($search->run(inspection: true)['results'])->toBe([]);

    config()->set('geoverify.commerce.inspection_fee_minor', 250_000);

    expect(collect($search->run(inspection: true)['results'])->pluck('id')->all())->toBe([$it['seller']['shop']->id]);
});

it('sorts nearest first to the cell centre, with unclaimed businesses after and never measured', function () {
    $ground = sweptGround();
    $it = shopWithCatalogue($ground);
    $reduced = signpostedShop('Unclaimed Kiosk', $ground);
    $centre = DB::selectOne('SELECT ST_X(centroid::geometry) AS lng, ST_Y(centroid::geometry) AS lat FROM structures WHERE id = ?', [$it['seller']['shop']->structure_id]);

    $rows = app(SearchDirectory::class)->run(sort: 'nearest', near: [(float) $centre->lng + 0.01, (float) $centre->lat])['results'];

    $published = rowFor($rows, $it['seller']['shop']->id);

    expect($rows[0]['id'])->toBe($it['seller']['shop']->id)
        ->and($published['distanceKm'])->toBeGreaterThan(0.5)->toBeLessThan(2.0)
        ->and(rowFor($rows, $reduced->id)['distanceKm'])->toBeNull();
});

it('searches the map box by cell for a published business and only by ward for an unclaimed one', function () {
    $ground = sweptGround();
    $it = shopWithCatalogue($ground);
    $reduced = signpostedShop('Unclaimed Kiosk', $ground);

    // The test ground carries no wards; give both shops one, as ingestion would.
    coveredGround();
    $wardId = DB::table('admin_boundaries')->where('code', 'TEST-WARD')->value('id');
    DB::table('structures')->whereIn('id', [$reduced->structure_id, $it['seller']['shop']->structure_id])->update(['ward_id' => $wardId]);

    $ward = DB::selectOne('SELECT ST_XMin(boundary) w, ST_YMin(boundary) s, ST_XMax(boundary) e, ST_YMax(boundary) n
        FROM admin_boundaries WHERE id = ?', [$wardId]);

    // A sliver in the corner of the ward, well away from either shop's cell.
    $sliver = [(float) $ward->w, (float) $ward->s, (float) $ward->w + 0.0005, (float) $ward->s + 0.0005];

    $ids = collect(app(SearchDirectory::class)->run(box: $sliver)['results'])->pluck('id');

    // The unclaimed shop matches by its ward, so a tight box never pins it
    // down finer than that; the published one is placed by its cell and is
    // not in this corner.
    expect($ids)->toContain($reduced->id)
        ->and($ids)->not->toContain($it['seller']['shop']->id);
});

it('makes an order from a business that does not deliver a free collection', function () {
    $it = shopWithCatalogue();
    openHours($it, [], delivers: false);

    $order = app(PlacePurchase::class)(
        $it['buyer'], $it['seller']['shop'], [$it['rice']->id => 1],
        ['name' => 'Tunde', 'phone' => '0803', 'address' => null],
        Protection::None, PayChannel::Card,
    );

    expect($order->fulfilment)->toBe('collection')
        ->and($order->delivery_minor)->toBe(0)
        ->and($order->delivery_address)->toBe('Collection from the shop');

    openHours($it, [], delivers: true);

    expect(fn () => app(PlacePurchase::class)(
        $it['buyer'], $it['seller']['shop'], [$it['rice']->id => 1],
        ['name' => 'Tunde', 'phone' => '0803', 'address' => ''],
        Protection::None, PayChannel::Card,
    ))->toThrow(RuntimeException::class, 'where to deliver');
});

it('lets a buyer save a listing, unsave it, and keeps nothing withheld in the list', function () {
    $ground = sweptGround();
    $it = shopWithCatalogue($ground);

    $this->actingAs($it['buyer'], 'portal')->post("/portal/saved/{$it['seller']['shop']->id}")->assertSessionHasNoErrors();
    $this->actingAs($it['buyer'], 'portal')->get('/portal/saved')->assertInertia(fn ($page) => $page->has('listings', 1));

    $this->actingAs($it['buyer'], 'portal')->post("/portal/saved/{$it['seller']['shop']->id}");
    $this->actingAs($it['buyer'], 'portal')->get('/portal/saved')->assertInertia(fn ($page) => $page->has('listings', 0));

    $withheld = signpostedShop('Hidden Shop', $ground);
    $withheld->update(['publication_state' => 'withheld']);

    $this->actingAs($it['buyer'], 'portal')->post("/portal/saved/{$withheld->id}")->assertNotFound();
});

it('takes a review only from the buyer of a released order, once, and without contact details', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);
    payFor($order);
    $reviews = app(ManageReviews::class);

    expect(fn () => $reviews->write($order->refresh(), $it['buyer'], 5, 'Great'))->toThrow(RuntimeException::class, 'delivered and released');

    app(ManagePurchase::class)->confirm($order, $it['buyer']);

    expect(fn () => $reviews->write($order->refresh(), $it['buyer'], 5, 'Call me on 0803 123 4567'))->toThrow(RuntimeException::class, 'phone numbers')
        ->and(fn () => $reviews->write($order, $it['buyer'], 5, 'Write to amaka@example.com'))->toThrow(RuntimeException::class, 'phone numbers')
        ->and(fn () => $reviews->write($order, claimant('Somebody', '08035550005')['account'], 5, null))->toThrow(RuntimeException::class, 'not yours');

    $this->actingAs($it['buyer'], 'portal')->post("/portal/purchases/{$order->id}/review", ['rating' => 4, 'body' => 'Rice was well packed.'])->assertSessionHasNoErrors();

    expect(fn () => $reviews->write($order, $it['buyer'], 5, null))->toThrow(RuntimeException::class, 'already reviewed');

    $row = rowFor(app(SearchDirectory::class)->run()['results'], $it['seller']['shop']->id);

    expect($row['rating'])->toBe(4.0)
        ->and($row['reviewCount'])->toBe(1)
        ->and($row['ordersCompleted'])->toBe(1);

    // The listing names the reviewer by first name only.
    $this->get("/directory/{$it['seller']['shop']->id}")
        ->assertInertia(fn ($page) => $page->where('reviews.0.by', 'Tunde')->where('reviews.0.body', 'Rice was well packed.'));
});

it('hides a reported review on an admin ruling, and keeps it', function () {
    $it = shopWithCatalogue();
    $order = placePurchase($it);
    payFor($order);
    app(ManagePurchase::class)->confirm($order, $it['buyer']);
    $review = app(ManageReviews::class)->write($order->refresh(), $it['buyer'], 1, 'Terrible, the owner is a thief.');

    $this->actingAs($it['seller']['account'], 'portal')->post("/portal/reviews/{$review->id}/report", ['reason' => 'This accuses us of a crime.'])->assertSessionHasNoErrors();

    $this->actingAs(person(Role::Supervisor))->post("/admin/reviews/{$review->id}", ['hide' => true, 'note' => 'x'])->assertForbidden();
    $this->actingAs(person(Role::Admin))->post("/admin/reviews/{$review->id}", ['hide' => true, 'note' => 'Defamatory.'])->assertSessionHasNoErrors();

    expect($review->refresh()->status)->toBe(Review::HIDDEN)
        ->and(DB::table('review_reports')->whereNull('resolved_at')->count())->toBe(0)
        ->and(rowFor(app(SearchDirectory::class)->run()['results'], $it['seller']['shop']->id)['reviewCount'])->toBe(0);
});

it('lets only somebody who can edit the business set its hours', function () {
    $it = shopWithCatalogue();
    $it['seller']['membership']->update(['role' => PartyRole::Viewer]);

    $this->actingAs($it['seller']['account'], 'portal')
        ->post("/portal/businesses/{$it['seller']['shop']->id}/profile", ['hours' => ['mon' => ['opens' => '08:00', 'closes' => '17:00']]])
        ->assertForbidden();
});
