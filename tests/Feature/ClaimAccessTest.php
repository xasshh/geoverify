<?php

declare(strict_types=1);

use App\Domain\Claim\Actions\ConfirmClaimCode;
use App\Domain\Claim\Actions\RequestClaimCode;
use App\Domain\Claim\Enums\ClaimRelationship;
use App\Domain\Claim\Events\ClaimCodeIssued;
use App\Domain\Claim\Models\Claim;
use App\Domain\Party\Enums\PartyRole;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\EnterpriseObservation;
use App\Enums\Role;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Claims: what a party may and may not reach
|--------------------------------------------------------------------------
|
| The register is private, and a claim is the only thing that opens any of it.
| These are the boundaries: whose claim you can see, what claiming lets you
| change, and above all that it never lets you change what an officer wrote.
|
*/

/**
 * Signs a party in and hands back a controlled listing.
 *
 * @return array{shop: Enterprise, who: array{party: Party, account: PortalAccount, membership: PartyUser}, claim: Claim}
 */
function controlledListing(): array
{
    Event::fake([ClaimCodeIssued::class]);

    $shop = enumeratedShop('Held Provisions', '0803 121 2121');
    $who = claimant('Holder', '08031212121');
    $claim = submitClaimFor($who, $shop);

    app(RequestClaimCode::class)($claim, '127.0.0.1');
    app(ConfirmClaimCode::class)($claim, latestClaimCode());

    return ['shop' => $shop, 'who' => $who, 'claim' => $claim];
}

it('keeps the whole claim surface behind the portal guard', function () {
    $shop = enumeratedShop('Locked Out Stores');

    $this->get('/portal/claim')->assertRedirect('/portal/sign-in');
    $this->post('/portal/claim', ['enterprise_id' => $shop->id, 'relationship' => 'owner'])
        ->assertRedirect('/portal/sign-in');
});

it('lets a controlling party open its own listing and nobody else', function () {
    ['shop' => $shop, 'who' => $who] = controlledListing();
    $stranger = claimant('Passing Stranger', '08039998888');

    $this->actingAs($who['account'], 'portal')
        ->get("/portal/businesses/{$shop->id}")
        ->assertOk();

    $this->actingAs($stranger['account'], 'portal')
        ->get("/portal/businesses/{$shop->id}")
        ->assertForbidden();
});

it('will not show one party the claim of another', function () {
    ['claim' => $claim] = controlledListing();
    $stranger = claimant('Nosy Party', '08037776666');

    $this->actingAs($stranger['account'], 'portal')
        ->get("/portal/claim/{$claim->id}")
        ->assertForbidden();
});

it('refuses to let a viewer claim a business for the party', function () {
    $shop = enumeratedShop('Viewer Test Stores');
    $who = claimant('Read Only', '08036665555');

    $who['membership']->forceFill(['role' => PartyRole::Viewer])->save();

    $this->actingAs($who['account'], 'portal')
        ->post('/portal/claim', [
            'enterprise_id' => $shop->id,
            'relationship' => ClaimRelationship::Owner->value,
        ])
        ->assertForbidden();
});

/*
| The rule the milestone turns on.
|
| A claimant who could rewrite an officer's observation would be able to keep
| the credibility a field visit gave the record while changing what it found,
| which is the single most valuable thing this platform sells. So it is proved
| two ways: structurally, that no portal route exists which could, and
| behaviourally, that exercising everything a controlling party can do leaves
| the observation byte for byte identical.
*/

it('exposes no portal route that could write to an observation', function () {
    // An allowlist rather than a pattern match. Adding a mutating portal route
    // is a decision somebody should have to make here, deliberately, rather
    // than something a clever regex silently permits.
    $allowed = [
        'portal.sign-in', 'portal.request-code', 'portal.verify', 'portal.verify.submit',
        'portal.register', 'portal.register.submit', 'portal.sign-out',
        'portal.dashboard', 'portal.claim.search', 'portal.claim.store',
        'portal.claim.show', 'portal.claim.code', 'portal.claim.confirm',
        'portal.listing',
        // Self-registration. These write structures and enterprises, and none
        // of them touches an observation belonging to somebody else: a party
        // authors the one observation its own registration creates, and can
        // never author or alter another.
        'portal.register-business', 'portal.register-business.name',
        'portal.register-business.place', 'portal.register-business.back',
        'portal.register-business.submit',
        /*
         * M5. All three write, and none of them writes an observation.
         *
         * A correction is a row in correction_proposals saying what a party
         * thinks is wrong; withdrawing sets that row's status; publication sets
         * a column on the enterprise. The observation an officer authored is
         * not reachable from any of them.
         *
         * The path that does append an observation is DecideCorrection, and it
         * runs from the console under a supervisor. That is the whole shape of
         * the milestone: a party proposes, a person rules, and what an officer
         * recorded is added to rather than replaced.
         */
        'portal.corrections.store', 'portal.corrections.withdraw', 'portal.publication',
        /*
         * M6. Buying a visit, and none of it writes an observation either.
         *
         * store creates a verification_orders row; pay asks the provider for a
         * checkout page and records nothing; return is inert by construction
         * and is tested as such. What an officer eventually records on the
         * visit is authored by the officer through the field capture flow,
         * which no portal session can reach.
         *
         * Nothing on this guard can mark an order paid. That is reachable only
         * from the provider's signed webhook, which is outside the portal
         * prefix entirely and so cannot appear in this list.
         */
        'portal.orders.create', 'portal.orders.store', 'portal.orders.show',
        'portal.orders.pay', 'portal.orders.return',
        /*
         * M7. The certificate, which is read only in both halves.
         *
         * certificate prints a document from what the register already holds
         * and appends a download event, nothing more. certificate.render is
         * the HTML the printing browser fetches: it sits outside the guarded
         * group because that browser has no session, and it is signed, short
         * lived and refused off the loopback interface.
         *
         * Neither writes an observation, and neither can: the certificate is
         * assembled from the accepted visit, and a party that disagrees with
         * what it says is asking for another visit rather than an edit.
         */
        'portal.certificate', 'portal.certificate.render',
        /*
         * P2. Photographs a business shows of itself, and taking one down.
         *
         * These write media rows authored by a party, which the database keeps
         * apart from an officer's photographs with a check constraint: exactly
         * one of captured_by and uploaded_by_party_id is set. Neither route can
         * touch an observation, and withdraw refuses anything that is not a
         * storefront photograph with a party author, so a business cannot use
         * it to erase the evidence of its own visit.
         *
         * The upload is the only portal route that puts something new in front
         * of strangers on the party's own authority rather than by proposing it
         * to a supervisor. That is correct: a photograph of your own shopfront
         * is an assertion about yourself, not a claim about what an officer saw.
         */
        'portal.photos.store', 'portal.photos.withdraw',
        /*
         * I1. What a business tells investors, and its data room.
         *
         * These write opportunities, data_room_documents and data_room_grants,
         * all authored by the controlling party and none of them an
         * observation. Publishing is the business's own consent to be read by
         * a verified investor; withdrawing keeps the row. Documents live on the
         * private disk and leave only through the investor guard's grant check.
         */
        'portal.investors', 'portal.investors.save', 'portal.investors.withdraw',
        'portal.investors.documents.store', 'portal.investors.documents.withdraw',
        'portal.investors.requests.decide',
        /*
         * The login mockups. A second way in (business ID or email with a
         * password), a code resend, a password reset proved by SMS, and the
         * account's own settings. They write portal_accounts and sign-in
         * codes, never an observation.
         */
        'portal.sign-in.password', 'portal.verify.resend', 'portal.forgot-password',
        'portal.reset-password', 'portal.reset-password.submit',
        'portal.settings', 'portal.settings.email', 'portal.settings.password',
        /*
         * M1, the merchant hub. Listings write products and party-authored
         * product photographs (media kind product, under media_one_author);
         * Team writes party_users; Verification and Orders only read. None
         * of them can reach an observation.
         */
        'portal.listings', 'portal.listings.store', 'portal.listings.update', 'portal.listings.withdraw',
        'portal.listings.photos.store', 'portal.listings.photos.withdraw',
        'portal.verification', 'portal.orders.index',
        'portal.team', 'portal.team.invite', 'portal.team.role', 'portal.team.revoke', 'portal.team.accept',
        /*
         * M2, buying. Checkout and the buyer's order write purchase_orders and
         * their lines, priced from products; dispatch, confirm, issue and
         * cancel move an order's status and post its ledger movement. The
         * wallet writes payout_accounts and payouts. None of them is a
         * payment: money is recorded as received only by the webhook, which
         * is not on this guard. None can reach an observation.
         */
        'portal.checkout', 'portal.checkout.store',
        'portal.purchases.index', 'portal.purchases.show', 'portal.purchases.pay', 'portal.purchases.return',
        'portal.purchases.confirm', 'portal.purchases.issue', 'portal.purchases.cancel',
        'portal.sales.show', 'portal.sales.dispatch',
        'portal.wallet', 'portal.wallet.account', 'portal.wallet.withdraw',
    ];

    $actual = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => $name !== null && str_starts_with($name, 'portal.'))
        ->unique()
        ->values()
        ->all();

    expect($actual)->toEqualCanonicalizing($allowed);
});

it('leaves the officer record untouched after a party does everything it can', function () {
    ['shop' => $shop, 'who' => $who, 'claim' => $claim] = controlledListing();

    $before = EnterpriseObservation::query()
        ->where('enterprise_id', $shop->id)
        ->orderBy('id')
        ->get()
        ->map(fn (EnterpriseObservation $o): array => $o->getAttributes())
        ->all();

    $as = $this->actingAs($who['account'], 'portal');

    // Every mutating route the portal has, fired at this listing by the party
    // that controls it.
    $as->get("/portal/businesses/{$shop->id}")->assertOk();
    $as->get("/portal/claim/{$claim->id}")->assertOk();
    $as->post("/portal/claim/{$claim->id}/code");
    $as->post("/portal/claim/{$claim->id}/confirm", ['code' => '123456']);
    $as->post('/portal/claim', [
        'enterprise_id' => $shop->id,
        'relationship' => ClaimRelationship::Owner->value,
    ]);

    // M5's additions, fired at the same listing. Proposing a correction is the
    // closest a party can get to editing the record, and the point of the
    // milestone is that it does not get there.
    $as->post("/portal/businesses/{$shop->id}/corrections", [
        'field' => 'trading_name',
        'proposed_value' => 'Rewritten By The Owner',
        'reason' => 'We would like this changed please.',
    ]);
    $as->post("/portal/businesses/{$shop->id}/publication", ['state' => 'opted_in']);

    $after = EnterpriseObservation::query()
        ->where('enterprise_id', $shop->id)
        ->orderBy('id')
        ->get()
        ->map(fn (EnterpriseObservation $o): array => $o->getAttributes())
        ->all();

    expect($after)->toBe($before);
});

it('stops an observation being rewritten even with a model in hand', function () {
    $shop = enumeratedShop('Immutable Stores');

    $observation = EnterpriseObservation::query()
        ->where('enterprise_id', $shop->id)
        ->firstOrFail();

    $original = $observation->trading_name;

    // Not a route, not a form: the model itself, held directly, which is the
    // shape any future portal feature would reach for.
    expect(fn () => $observation->update(['trading_name' => 'Rewritten By The Owner']))
        ->toThrow(RuntimeException::class);

    expect(fn () => $observation->delete())->toThrow(RuntimeException::class);

    expect(EnterpriseObservation::query()->find($observation->id)->trading_name)
        ->toBe($original);
});

/*
| The console side. The queue is a supervisor tool and nothing else reaches it.
*/

it('shows a supervisor the claims a rule could not settle', function () {
    $shop = enumeratedShop('Needs A Person', null);
    $who = claimant('Patient Party', '08034443333');
    submitClaimFor($who, $shop);

    $this->actingAs(person(Role::Supervisor, 'Queue reader'))
        ->get('/console/claims')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('console/Claims')
            ->has('claims', 1)
            ->where('claims.0.tradingName', 'Needs A Person'));
});

it('refuses a decision with no reason attached to it', function () {
    $shop = enumeratedShop('Unreasoned', null);
    $who = claimant('Some Party', '08032221111');
    $claim = submitClaimFor($who, $shop);

    $supervisor = person(Role::Supervisor, 'Deciding supervisor');

    // An approval without a stated reason is exactly as unaccountable as a
    // rejection without one, and it is the approvals that get questioned.
    $this->actingAs($supervisor)
        ->post("/console/claims/{$claim->id}", ['decision' => 'approve', 'note' => ''])
        ->assertSessionHasErrors('note');

    expect($claim->refresh()->status->value)->toBe('submitted');

    $this->actingAs($supervisor)
        ->post("/console/claims/{$claim->id}", [
            'decision' => 'approve',
            'note' => 'CAC certificate and tenancy sighted in person.',
        ])
        ->assertSessionHasNoErrors();

    expect($claim->refresh()->status->value)->toBe('approved')
        ->and($claim->decision)->toBe(Claim::DECISION_REVIEWED)
        ->and($claim->decided_by)->toBe($supervisor->id);
});

it('keeps an officer out of the claim queue', function () {
    $this->actingAs(person(Role::Officer, 'Curious officer'))
        ->get('/console/claims')
        ->assertRedirect();
});
