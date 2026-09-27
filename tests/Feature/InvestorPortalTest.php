<?php

declare(strict_types=1);

use App\Domain\Claim\Actions\GrantControl;
use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Investment\Actions\ManageOpportunity;
use App\Domain\Investment\Actions\ReadDossier;
use App\Domain\Investment\Actions\ReadInvestorMap;
use App\Domain\Investment\Actions\ReadOpportunities;
use App\Domain\Investment\Actions\RequestInvestorAccess;
use App\Domain\Investment\Models\DataRoomGrant;
use App\Domain\Investment\Models\InvestorOrganisation;
use App\Domain\Investment\Models\InvestorUser;
use App\Domain\Investment\Models\Opportunity;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Enums\PublicationState;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Actions\PlaceOrder;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Models\VerificationEvent;
use App\Domain\Verification\Models\VerificationOrder;
use App\Enums\Role;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * I1: the investor portal.
 *
 * What is worth testing is what an investor can reach, not how it looks: that
 * only a business's own act puts it in front of an investor, that KYC gates
 * everything that names a business, that the data room opens only on the
 * business's say, and that the dossier never carries a phone, an email or a
 * coordinate.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    Storage::fake('media');
});

/**
 * A shop on the shared ground, claimed and controlled.
 *
 * @param  ArrayObject<string, mixed>  $ground
 * @return array{party: Party, account: PortalAccount, shop: Enterprise}
 */
function ownedShop(ArrayObject $ground, string $tradingName, string $phone): array
{
    $shop = enumeratedShop($tradingName, $phone, $ground);
    $who = claimant('Owner of '.$tradingName, $phone);
    app(GrantControl::class)->grant(submitClaimFor($who, $shop));

    return ['party' => $who['party'], 'account' => $who['account'], 'shop' => $shop->refresh()];
}

function investor(bool $verified = true, string $email = 'analyst@harbour.test'): InvestorUser
{
    $user = app(RequestInvestorAccess::class)([
        'organisation' => 'Harbour Capital '.str()->random(4),
        'kind' => 'fund',
        'name' => 'Kemi Adeyemi',
        'title' => 'Analyst',
        'email' => $email,
        'password' => 'correct horse battery',
    ]);

    if ($verified) {
        $user->organisation?->forceFill(['kyc_status' => InvestorOrganisation::KYC_VERIFIED])->save();
    }

    return $user->refresh();
}

/**
 * A claimed shop whose owner has published an opportunity.
 *
 * @param  ArrayObject<string, mixed>  $ground
 * @return array{shop: Enterprise, opportunity: Opportunity, party: Party, account: PortalAccount}
 */
function publishedOpportunity(ArrayObject $ground, string $tradingName = 'Kaduna Grain Millers', string $phone = '08035550001'): array
{
    $it = ownedShop($ground, $tradingName, $phone);
    $manage = app(ManageOpportunity::class);

    $opportunity = $manage->save($it['shop'], $it['party'], [
        'seeking' => 'expansion_equity',
        'ticket_size_naira' => 50_000_000,
        'use_of_funds' => 'A second milling line',
        'operating_since' => 2014,
    ]);
    $manage->publish($opportunity, $it['party']);

    return ['shop' => $it['shop'], 'opportunity' => $opportunity->refresh(), 'party' => $it['party'], 'account' => $it['account']];
}

it('keeps the investor portal behind its own guard', function () {
    // One patch of ground for every shop this test makes: H3 cells are
    // unique across the grid, so a second mandate over it would have none.
    $ground = sweptGround();
    $this->get('/invest')->assertRedirect('/invest/sign-in');

    // An investor session reaches nothing on the portal or the console.
    // Logged in on its guard without making it the default one, as a real
    // request would be: actingAs() also switches the default guard.
    $investor = investor();
    auth()->guard('investor')->login($investor);
    $this->get('/portal')->assertRedirect('/portal/sign-in');
    $this->get('/console/review')->assertRedirect('/login');

    // And a portal session is not an investor session, by construction.
    auth()->guard('investor')->logout();
    $it = ownedShop($ground, 'Portal Person Stores', '08035550100');
    $this->actingAs($it['account'], 'portal')->get('/invest')->assertRedirect('/invest/sign-in');
});

it('lets an organisation request access and read the overview before KYC, and nothing that names a business', function () {
    // One patch of ground for every shop this test makes: H3 cells are
    // unique across the grid, so a second mandate over it would have none.
    $ground = sweptGround();
    publishedOpportunity($ground);

    $this->post('/invest/request-access', [
        'organisation' => 'Lagos Angels',
        'kind' => 'angel',
        'name' => 'Tunde Bakare',
        'email' => 'Tunde@Angels.test',
        'password' => 'long enough password',
        'password_confirmation' => 'long enough password',
    ])->assertRedirect('/invest');

    $user = InvestorUser::query()->where('email', 'tunde@angels.test')->firstOrFail();
    expect($user->organisation?->kyc_status)->toBe(InvestorOrganisation::KYC_PENDING);

    // The overview renders, and ships no opportunity at all: hiding rows in
    // the page would still have sent them.
    $this->actingAs($user, 'investor')->get('/invest')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('invest/Overview')->where('featured', []));

    $this->actingAs($user, 'investor')->get('/invest/opportunities')->assertRedirect('/invest');
    $this->actingAs($user, 'investor')->get('/invest/watchlist')->assertRedirect('/invest');
});

it('shows an opportunity only while its owner publishes it and still controls the business', function () {
    // One patch of ground for every shop this test makes: H3 cells are
    // unique across the grid, so a second mandate over it would have none.
    $ground = sweptGround();
    $it = publishedOpportunity($ground);
    $investor = investor();
    $read = app(ReadOpportunities::class);

    expect(array_column($read->rows($investor->investor_organisation_id), 'id'))->toBe([$it['opportunity']->id]);

    // Withdrawn: gone, and the row is kept.
    app(ManageOpportunity::class)->withdraw($it['opportunity'], $it['party']);
    expect($read->rows($investor->investor_organisation_id))->toBe([]);
    expect(Opportunity::query()->find($it['opportunity']->id))->not->toBeNull();

    // Published again but control revoked: gone too.
    $again = publishedOpportunity($ground, 'Lekki Cold Chain', '08035550002');
    PartyBusiness::query()->where('enterprise_id', $again['shop']->id)->update(['status' => PartyBusiness::STATUS_REVOKED]);
    expect($read->rows($investor->investor_organisation_id))->toBe([]);

    // And a business that asked to be withheld is withheld here as well.
    $third = publishedOpportunity($ground, 'Ogun Ceramics', '08035550003');
    $third['shop']->forceFill(['publication_state' => PublicationState::Withheld])->save();
    expect($read->rows($investor->investor_organisation_id))->toBe([]);
});

it('never puts a phone, an email or a coordinate in the dossier', function () {
    // One patch of ground for every shop this test makes: H3 cells are
    // unique across the grid, so a second mandate over it would have none.
    $ground = sweptGround();
    $it = publishedOpportunity($ground, 'Plateau Tin Recovery', '0803 555 0004');
    $investor = investor();

    $dossier = app(ReadDossier::class)($it['opportunity']->id, $investor->investor_organisation_id);
    expect($dossier)->not->toBeNull();

    $encoded = json_encode($dossier, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('0803')
        ->and($encoded)->not->toContain('555')
        ->and($encoded)->not->toContain('@')
        ->and($encoded)->not->toMatch('/\b\d{1,2}\.\d{4,}\b/');

    // The position leaves as a resolution 7 cell and nothing finer.
    expect($dossier['cell'])->toBeString()
        ->and(strlen((string) $dossier['cell']))->toBe(15);
});

it('opens a data room only when the business grants it, and closes it when revoked', function () {
    // One patch of ground for every shop this test makes: H3 cells are
    // unique across the grid, so a second mandate over it would have none.
    $ground = sweptGround();
    $it = publishedOpportunity($ground);
    $investor = investor();

    $this->actingAs($it['account'], 'portal')
        ->post("/portal/businesses/{$it['shop']->id}/investors/documents", [
            'title' => 'Audited accounts 2024 to 2025',
            'document' => UploadedFile::fake()->create('accounts.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

    $document = $it['opportunity']->documents()->firstOrFail();
    $url = "/invest/opportunities/{$it['opportunity']->id}/documents/{$document->id}";

    $this->actingAs($investor, 'investor')->get($url)->assertForbidden();

    $this->actingAs($investor, 'investor')->post("/invest/opportunities/{$it['opportunity']->id}/data-room")->assertRedirect();
    $grant = DataRoomGrant::query()->firstOrFail();
    expect($grant->status)->toBe(DataRoomGrant::STATUS_REQUESTED);

    $this->actingAs($investor, 'investor')->get($url)->assertForbidden();

    $this->actingAs($it['account'], 'portal')
        ->post("/portal/businesses/{$it['shop']->id}/investors/requests/{$grant->id}", ['decision' => 'granted'])
        ->assertRedirect();

    $this->actingAs($investor, 'investor')->get($url)->assertOk();

    $this->actingAs($it['account'], 'portal')
        ->post("/portal/businesses/{$it['shop']->id}/investors/requests/{$grant->id}", ['decision' => 'revoked'])
        ->assertRedirect();

    $this->actingAs($investor, 'investor')->get($url)->assertForbidden();
});

it('will not let one business manage another business\'s investors page', function () {
    // One patch of ground for every shop this test makes: H3 cells are
    // unique across the grid, so a second mandate over it would have none.
    $ground = sweptGround();
    $mine = publishedOpportunity($ground);
    $stranger = ownedShop($ground, 'Other Shop', '08035550099');

    $this->actingAs($stranger['account'], 'portal')
        ->get("/portal/businesses/{$mine['shop']->id}/investors")
        ->assertForbidden();

    $this->actingAs($stranger['account'], 'portal')
        ->post("/portal/businesses/{$mine['shop']->id}/investors/withdraw")
        ->assertForbidden();
});

it('keeps watchlists and notes to the organisation that made them', function () {
    // One patch of ground for every shop this test makes: H3 cells are
    // unique across the grid, so a second mandate over it would have none.
    $ground = sweptGround();
    $it = publishedOpportunity($ground);
    $ours = investor(email: 'ours@fund.test');
    $theirs = investor(email: 'theirs@fund.test');
    $id = $it['opportunity']->id;

    $this->actingAs($ours, 'investor')->post("/invest/opportunities/{$id}/watch")->assertRedirect();
    $this->actingAs($ours, 'investor')->post("/invest/opportunities/{$id}/note", ['body' => 'Strong offtake contracts'])->assertRedirect();

    $mine = app(ReadDossier::class)($id, $ours->investor_organisation_id);
    $other = app(ReadDossier::class)($id, $theirs->investor_organisation_id);

    expect($mine['watching'])->toBeTrue()
        ->and($mine['note'])->toBe('Strong offtake contracts')
        ->and($other['watching'])->toBeFalse()
        ->and($other['note'])->toBeNull();

    // Interest is the one thing the business learns, as a count.
    $this->actingAs($theirs, 'investor')->post("/invest/opportunities/{$id}/interest")->assertRedirect();
    expect(app(ReadDossier::class)($id, $ours->investor_organisation_id)['seeking']['interested'])->toBe(1);
});

it('leaves the KYC ruling to an admin, and refuses a supervisor', function () {
    $investor = investor(verified: false);
    $organisation = $investor->organisation;

    $this->actingAs(person(Role::Supervisor), 'web')
        ->post("/admin/investors/{$organisation?->id}/decide", ['decision' => 'verified', 'note' => 'Checked'])
        ->assertForbidden();

    $this->actingAs(person(Role::Admin), 'web')
        ->post("/admin/investors/{$organisation?->id}/decide", ['decision' => 'verified', 'note' => 'CAC and SEC licence checked'])
        ->assertRedirect();

    expect($organisation?->refresh()->kyc_status)->toBe(InvestorOrganisation::KYC_VERIFIED);

    // Suspension takes effect on the very next request.
    $this->actingAs(person(Role::Admin), 'web')
        ->post("/admin/investors/{$organisation?->id}/decide", ['decision' => 'suspended', 'note' => 'Licence lapsed']);

    $this->actingAs($investor->refresh(), 'investor')->get('/invest')->assertForbidden();
});

/*
| I2: an investor commissions a visit.
*/

it('lets a verified investor commission a visit to a business it can see, paid by nobody but the webhook', function () {
    $ground = sweptGround();
    $it = publishedOpportunity($ground);
    $investor = investor();

    $this->actingAs($investor, 'investor')
        ->get("/invest/opportunities/{$it['opportunity']->id}/commission")
        ->assertOk();

    $this->actingAs($investor, 'investor')
        ->post("/invest/opportunities/{$it['opportunity']->id}/commission", ['tier' => 'location_verified', 'urgency' => 'standard'])
        ->assertRedirect();

    $order = VerificationOrder::query()->sole();

    expect($order->investor_organisation_id)->toBe($investor->investor_organisation_id)
        ->and($order->party_id)->toBeNull()
        ->and($order->ordered_by_investor)->toBe($investor->id)
        ->and($order->status)->toBe(OrderStatus::AwaitingPayment);

    expect(VerificationEvent::query()->where('event', 'order.placed')->value('actor_type'))
        ->toBe(VerificationEvent::ACTOR_INVESTOR);

    // The return from the provider changes nothing.
    $this->actingAs($investor, 'investor')->get("/invest/verifications/{$order->id}/return")->assertRedirect();
    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingPayment);

    // The signed webhook does, exactly as for a business.
    $delivery = paystackDelivery($order);
    $this->postJson('/webhooks/paystack', $delivery['payload'], ['x-paystack-signature' => $delivery['signature']])->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);

    // The business sees an officer is coming, and not who asked.
    $this->actingAs($it['account'], 'portal')->get('/portal')
        ->assertInertia(fn ($page) => $page
            ->where('focus.orders.0.byInvestor', true)
            ->where('focus.inFlight', null));
});

it('keeps a commission to the organisation that placed it', function () {
    $ground = sweptGround();
    $it = publishedOpportunity($ground);
    $ours = investor(email: 'ours@fund.test');
    $theirs = investor(email: 'theirs@fund.test');

    $order = app(PlaceOrder::class)->forInvestor($ours, $it['shop'], 'location_verified');

    $this->actingAs($theirs, 'investor')->get("/invest/verifications/{$order->id}")->assertNotFound();
    $this->actingAs($theirs, 'investor')->post("/invest/verifications/{$order->id}/pay")->assertNotFound();
    $this->actingAs($it['account'], 'portal')->get("/portal/orders/{$order->id}")->assertForbidden();
});

it('refuses a commission to a business that has withdrawn, or from an unverified organisation', function () {
    $ground = sweptGround();
    $it = publishedOpportunity($ground);
    app(ManageOpportunity::class)->withdraw($it['opportunity'], $it['party']);

    $this->actingAs(investor(), 'investor')
        ->post("/invest/opportunities/{$it['opportunity']->id}/commission", ['tier' => 'location_verified', 'urgency' => 'standard'])
        ->assertNotFound();

    $pending = investor(verified: false, email: 'pending@fund.test');
    expect(fn () => app(PlaceOrder::class)->forInvestor($pending, $it['shop'], 'location_verified'))
        ->toThrow(RuntimeException::class, 'verified');

    expect(VerificationOrder::query()->count())->toBe(0);
});

it('holds exactly one payer on every order, in the database', function () {
    $ground = sweptGround();
    $it = publishedOpportunity($ground);
    $investor = investor();

    $order = app(PlaceOrder::class)->forInvestor($investor, $it['shop'], 'location_verified');

    expect(fn () => DB::table('verification_orders')->where('id', $order->id)->update(['party_id' => $it['party']->id]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('verification_orders')->where('id', $order->id)->update(['investor_organisation_id' => null]))
        ->toThrow(QueryException::class);
});

it('draws no hexagon that could point at fewer than three businesses, and no opportunity for an unverified organisation', function () {
    $ground = sweptGround();
    $map = app(ReadInvestorMap::class);

    signpostedShop('First Signposted', $ground);
    signpostedShop('Second Signposted', $ground);

    expect($map(1, true)['hexes']['features'])->toBe([]);

    signpostedShop('Third Signposted', $ground);

    $hexes = $map(1, true)['hexes']['features'];
    expect($hexes)->toHaveCount(1)
        ->and($hexes[0]['properties']['count'])->toBe(3);

    publishedOpportunity($ground);
    expect($map(1, false)['opportunities']['features'])->toBe([])
        ->and($map(1, true)['opportunities']['features'])->toHaveCount(1);
});
