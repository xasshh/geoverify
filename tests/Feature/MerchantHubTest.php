<?php

declare(strict_types=1);

use App\Domain\Catalogue\Actions\ReadListingStrength;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Media\Models\Media;
use App\Domain\Party\Events\SignInCodeIssued;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Actions\SearchDirectory;
use App\Domain\Registry\Actions\SetPublicationState;
use App\Domain\Registry\Enums\PublicationState;
use App\Domain\Registry\Models\Enterprise;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;

/**
 * M1: the merchant hub.
 *
 * The catalogue is the business's own statement and appears in public only
 * once the listing is published. Team access is the owner's to give, and a
 * number is added to a business only when the person holding it proves it.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    Storage::fake('media');
    Event::fake([SignInCodeIssued::class]);
});

/**
 * @param  array{account: PortalAccount, shop: Enterprise}  $it
 * @param  array<string, mixed>  $data
 */
function addProduct(TestCase $case, array $it, array $data = []): Product
{
    $case->actingAs($it['account'], 'portal')
        ->post("/portal/businesses/{$it['shop']->id}/listings", $data + [
            'name' => 'Ofada rice',
            'unit' => '50 kg bag',
            'price_naira' => 78000,
        ])->assertRedirect();

    return Product::query()->where('enterprise_id', $it['shop']->id)->latest('id')->firstOrFail();
}

it('lets the business keep its own catalogue, with its own photographs', function () {
    $it = shopControlledBy('Amaka Okafor', '08037770001', 'Amaka Fresh Foods');
    $product = addProduct($this, $it);

    expect($product->price_minor)->toBe(7_800_000)
        ->and($product->party_id)->toBe($it['party']->id);

    $this->actingAs($it['account'], 'portal')
        ->post("/portal/businesses/{$it['shop']->id}/listings/{$product->id}/photos", [
            'photo' => UploadedFile::fake()->image('rice.jpg', 800, 600),
        ])->assertRedirect()->assertSessionHasNoErrors();

    $photo = $product->photos()->sole();
    expect($photo->kind)->toBe(Media::KIND_PRODUCT)
        ->and($photo->uploaded_by_party_id)->toBe($it['party']->id)
        ->and($photo->captured_by)->toBeNull();

    $this->actingAs($it['account'], 'portal')
        ->get("/portal/businesses/{$it['shop']->id}/listings")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Listings')->has('products', 1)->where('canEdit', true));

    // Taking it down keeps the row.
    $this->actingAs($it['account'], 'portal')
        ->post("/portal/businesses/{$it['shop']->id}/listings/{$product->id}/withdraw")
        ->assertRedirect();
    expect($product->refresh()->status)->toBe(Product::STATUS_WITHDRAWN);
});

it('keeps another business, and a viewer, out of the catalogue', function () {
    $ground = sweptGround();
    $it = ownedShop($ground, 'Mine Stores', '08037770002');
    $stranger = ownedShop($ground, 'Other Stores', '08037770003');

    $this->actingAs($stranger['account'], 'portal')
        ->post("/portal/businesses/{$it['shop']->id}/listings", ['name' => 'Intruder'])
        ->assertForbidden();

    // A second person on the business, who may look and not touch.
    $viewer = PortalAccount::query()->create(['name' => 'Viewer', 'phone' => '+2348037770004', 'status' => 'active']);
    PartyUser::query()->create([
        'party_id' => $it['party']->id, 'portal_account_id' => $viewer->id, 'role' => 'viewer', 'accepted_at' => now(),
    ]);

    $this->actingAs($viewer, 'portal')
        ->get("/portal/businesses/{$it['shop']->id}/listings")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('canEdit', false));

    $this->actingAs($viewer, 'portal')
        ->post("/portal/businesses/{$it['shop']->id}/listings", ['name' => 'Viewer product'])
        ->assertForbidden();
});

it('will not reach a photograph that is not this product\'s own', function () {
    $it = shopControlledBy('Owner', '08037770005', 'Photo Stores');
    $product = addProduct($this, $it);

    $this->actingAs($it['account'], 'portal')
        ->post("/portal/businesses/{$it['shop']->id}/photos", ['photo' => UploadedFile::fake()->image('front.jpg')]);
    $storefront = Media::query()->where('kind', Media::KIND_STOREFRONT)->sole();

    $this->actingAs($it['account'], 'portal')
        ->post("/portal/businesses/{$it['shop']->id}/listings/{$product->id}/photos/{$storefront->id}/withdraw")
        ->assertNotFound();

    expect($storefront->refresh()->status)->toBe(Media::STATUS_STORED);
});

it('shows a catalogue in public only on a published listing, and only its fixed fields', function () {
    $it = shopControlledBy('Publisher', '08037770006', 'Published Provisions');
    addProduct($this, $it);
    addProduct($this, $it, ['name' => 'Kept back', 'status' => 'hidden']);

    $search = app(SearchDirectory::class);

    // Claimed but private: not in the directory at all, so no catalogue either.
    expect($search->productsFor(['id' => $it['shop']->id, 'depth' => 'reduced']))->toBe([]);

    app(SetPublicationState::class)($it['party'], $it['account'], $it['shop'], PublicationState::OptedIn);

    $page = $this->get("/directory/{$it['shop']->id}")->assertOk();
    $page->assertInertia(fn ($p) => $p->has('products', 1)->where('products.0.name', 'Ofada rice'));

    $products = $page->viewData('page')['props']['products'];
    expect(array_keys($products[0]))->toEqualCanonicalizing(['id', 'name', 'unit', 'priceNaira', 'description', 'photos']);
});

it('adds a person to a business only when they prove the invited number and accept', function () {
    $it = shopControlledBy('Owner Person', '08037770007', 'Team Stores');

    $this->actingAs($it['account'], 'portal')
        ->post('/portal/team', ['name' => 'Tunde Staff', 'phone' => '08037770008', 'role' => 'manager'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $invite = PartyUser::query()->where('party_id', $it['party']->id)->whereNull('accepted_at')->sole();
    $invitee = PortalAccount::query()->findOrFail($invite->portal_account_id);

    // Before accepting, the invitee acts for nothing.
    $this->actingAs($invitee, 'portal')->get("/portal/businesses/{$it['shop']->id}/listings")->assertForbidden();

    // Somebody else cannot accept it.
    $stranger = claimant('Stranger', '08037770009');
    $this->actingAs($stranger['account'], 'portal')->post("/portal/team/{$invite->id}/accept")->assertNotFound();

    $this->actingAs($invitee, 'portal')->post("/portal/team/{$invite->id}/accept")->assertRedirect('/portal');
    expect($invite->refresh()->accepted_at)->not->toBeNull();

    $this->actingAs($invitee, 'portal')->get("/portal/businesses/{$it['shop']->id}/listings")->assertOk();

    // A manager cannot hand out access.
    $this->actingAs($invitee, 'portal')
        ->post('/portal/team', ['name' => 'Another', 'phone' => '08037770010', 'role' => 'viewer'])
        ->assertSessionHasErrors('phone');

    // The owner removes them, and the access is gone on the next request.
    $this->actingAs($it['account'], 'portal')->post("/portal/team/{$invite->id}/revoke")->assertRedirect();
    $this->actingAs($invitee->refresh(), 'portal')->get("/portal/businesses/{$it['shop']->id}/listings")->assertForbidden();
});

it('never lets the owner be demoted or removed', function () {
    $it = shopControlledBy('Sole Owner', '08037770011', 'Owner Stores');
    $owner = PartyUser::query()->where('portal_account_id', $it['account']->id)->sole();

    $this->actingAs($it['account'], 'portal')->post("/portal/team/{$owner->id}/revoke")->assertSessionHasErrors('role');
    $this->actingAs($it['account'], 'portal')->post("/portal/team/{$owner->id}/role", ['role' => 'viewer'])->assertSessionHasErrors('role');

    expect($owner->refresh()->revoked_at)->toBeNull()
        ->and($owner->role->value)->toBe('owner');
});

it('scores listing strength from what the business can do next', function () {
    $it = shopControlledBy('Strength Owner', '08037770012', 'Strength Stores');
    $strength = app(ReadListingStrength::class);

    $before = $strength($it['shop']);

    addProduct($this, $it, ['name' => 'One']);
    addProduct($this, $it, ['name' => 'Two']);
    addProduct($this, $it, ['name' => 'Three']);

    $after = $strength($it['shop']->refresh());

    expect($after['percent'])->toBe($before['percent'] + 20)
        ->and($after['missing'])->not->toContain('add 3 more products');
});

it('lists every verification on the businesses in the orders page', function () {
    $it = shopControlledBy('Orders Owner', '08037770013', 'Orders Stores');
    placeOrderFor($it);

    $this->actingAs($it['account'], 'portal')
        ->get('/portal/orders')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Orders')->has('orders', 1)->where('orders.0.open', true));
});
