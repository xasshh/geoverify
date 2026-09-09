<?php

declare(strict_types=1);

use App\Domain\Claim\Actions\GrantControl;
use App\Domain\Registry\Actions\SearchDirectory;
use App\Domain\Registry\Actions\SetPublicationState;
use App\Domain\Registry\Actions\WithholdOnRequest;
use App\Domain\Registry\Enums\PublicationState;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\EnterpriseObservation;
use App\Domain\Verification\Models\VerificationEvent;
use Database\Seeders\VerificationPricingSeeder;

/**
 * P2: the directory anybody can read.
 *
 * The first surface in this system that needs no account, which makes its
 * projection the thing worth testing rather than its layout. Every assertion
 * below is about what does or does not leave the building.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
});

/**
 * Puts a shop in the directory's reduced depth: signage, and nobody's claim.
 *
 * Takes the ground it stands on, because H3 indexes are unique across the whole
 * grid: a second mandate over the same polygon generates no cells at all, and a
 * test that wants two shops has to put them on one patch.
 *
 * @param  ArrayObject<string, mixed>|null  $ground
 */
function signpostedShop(string $name = 'Signposted Stores', ?ArrayObject $ground = null): Enterprise
{
    $shop = enumeratedShop($name, '0803 123 4567', $ground);

    EnterpriseObservation::query()
        ->where('enterprise_id', $shop->id)
        ->update(['signage_observed' => true]);

    return $shop->refresh();
}

it('shows a business that put its name on the street, and nothing more of it', function () {
    $shop = signpostedShop();

    $page = app(SearchDirectory::class)->run();
    $row = collect($page['results'])->firstWhere('id', $shop->id);

    expect($row)->not->toBeNull()
        ->and($row['depth'])->toBe('reduced')
        ->and($row['tradingName'])->toBe('Signposted Stores');

    // The whole projection, named. A key appearing here that is not in this
    // list is a disclosure somebody added without deciding to.
    expect(array_keys($row))->toEqualCanonicalizing([
        'id', 'depth', 'tradingName', 'sector', 'sectorCode', 'structureType',
        'ward', 'lga', 'tier', 'verified', 'openingHours',
    ]);

    // And the things that must never appear, whatever the state.
    $encoded = json_encode($row, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('0803')
        ->and($encoded)->not->toContain('phone')
        ->and($encoded)->not->toContain('email')
        ->and($encoded)->not->toContain('latitude')
        ->and($encoded)->not->toContain('longitude')
        ->and($encoded)->not->toContain('photograph')
        ->and($encoded)->not->toContain('accuracy');
});

it('keeps a business with no signage out of the directory entirely', function () {
    $shop = enumeratedShop('Unsignposted Provisions');

    EnterpriseObservation::query()
        ->where('enterprise_id', $shop->id)
        ->update(['signage_observed' => false]);

    $page = app(SearchDirectory::class)->run();

    // A business that did not put its name on the street has published
    // nothing, and enumeration is not consent to publish it for them.
    expect(collect($page['results'])->firstWhere('id', $shop->id))->toBeNull();
});

it('never shows a withheld listing, however signposted it is', function () {
    $shop = signpostedShop('Withheld Ventures');

    app(WithholdOnRequest::class)($shop);

    expect(collect(app(SearchDirectory::class)->run()['results'])->firstWhere('id', $shop->id))
        ->toBeNull();
});

it('opens a listing up when its owner claims it and opts in', function () {
    $it = buyerWithShop();
    $shop = $it['shop'];

    EnterpriseObservation::query()
        ->where('enterprise_id', $shop->id)
        ->update(['signage_observed' => true, 'opening_hours' => 'Mon to Sat, 08:00 to 18:00']);

    // Claimed but not opted in is still reduced: control is not consent.
    $before = collect(app(SearchDirectory::class)->run()['results'])->firstWhere('id', $shop->id);
    expect($before)->toBeNull();

    app(SetPublicationState::class)(
        $it['party'],
        $it['account'],
        $shop->refresh(),
        PublicationState::OptedIn,
    );

    $after = collect(app(SearchDirectory::class)->run()['results'])->firstWhere('id', $shop->id);

    expect($after)->not->toBeNull()
        ->and($after['depth'])->toBeIn(['claimed', 'verified'])
        // Opening hours appear only once somebody with control published them.
        ->and($after['openingHours'])->toBe('Mon to Sat, 08:00 to 18:00');
});

it('serves the directory to somebody with no account at all', function () {
    signpostedShop('Open To Everybody');

    $this->get('/directory')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('public/Directory')
            ->where('results.0.tradingName', 'Open To Everybody'));
});

it('keeps an unclaimed listing out of search engines and lets a published one in', function () {
    $ground = sweptGround();
    $shop = signpostedShop('Indexing Test Stores', $ground);

    // Reduced: present, and explicitly not indexable. Indexing is the step
    // that cannot be taken back, so it waits for consent.
    $this->get("/directory/{$shop->id}")
        ->assertOk()
        ->assertSee('noindex', false);

    $who = claimant('Owner Person', '08039990123');
    app(GrantControl::class)->grant(submitClaimFor($who, $shop));

    app(SetPublicationState::class)(
        $who['party'],
        $who['account'],
        $shop->refresh(),
        PublicationState::OptedIn,
    );

    $this->get("/directory/{$shop->id}")
        ->assertOk()
        ->assertDontSee('noindex', false);
});

it('takes a listing down for anybody who asks, with no account and no proof', function () {
    $shop = signpostedShop('Remove Me Stores');

    // No session, no claim, no evidence. Being left alone must not require
    // proving you own the thing you want left alone.
    $this->post("/directory/{$shop->id}/remove", ['reason' => 'We never asked to be listed.'])
        ->assertRedirect();

    expect($shop->refresh()->publication_state)->toBe(PublicationState::Withheld)
        ->and(collect(app(SearchDirectory::class)->run()['results'])->firstWhere('id', $shop->id))
        ->toBeNull();

    $events = VerificationEvent::query()
        ->where('subject_id', $shop->id)
        ->pluck('event');

    expect($events)->toContain('publication.withheld');
});

it('will not let a stranger overrule an owner who published deliberately', function () {
    $it = buyerWithShop('Publishing Owner', '08039990456');
    $shop = $it['shop'];

    app(SetPublicationState::class)(
        $it['party'],
        $it['account'],
        $shop,
        PublicationState::OptedIn,
    );

    $this->post("/directory/{$shop->id}/remove")->assertRedirect();

    // Still published: an owner's decision has a consent receipt behind it and
    // a stranger does not get to reverse it from a public page. The request is
    // recorded rather than dropped, which is where that conflict belongs.
    expect($shop->refresh()->publication_state)->toBe(PublicationState::OptedIn)
        ->and(VerificationEvent::query()->where('subject_id', $shop->id)->pluck('event'))
        ->toContain('publication.removal_requested');
});

it('filters by sector without widening what a row says', function () {
    $shop = signpostedShop('Sector Filter Stores');

    $page = app(SearchDirectory::class)->run(sector: $shop->sector_code);

    expect($page['results'])->not->toBeEmpty();

    foreach ($page['results'] as $row) {
        expect($row['sectorCode'])->toBe($shop->sector_code)
            ->and(array_keys($row))->toEqualCanonicalizing([
                'id', 'depth', 'tradingName', 'sector', 'sectorCode', 'structureType',
                'ward', 'lga', 'tier', 'verified', 'openingHours',
            ]);
    }
});
