<?php

declare(strict_types=1);

use App\Domain\Claim\Actions\GrantControl;
use App\Domain\Media\Actions\PublishStorefrontPhoto;
use App\Domain\Media\Models\Media;
use App\Domain\Registry\Actions\ReadDirectorySectors;
use App\Domain\Registry\Actions\SearchDirectory;
use App\Domain\Registry\Actions\SetPublicationState;
use App\Domain\Registry\Actions\WithholdOnRequest;
use App\Domain\Registry\Enums\PublicationState;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\EnterpriseObservation;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * P2: the directory anybody can read.
 *
 * The first surface in this system that needs no account, which makes its
 * projection the thing worth testing rather than its layout. Every assertion
 * below is about what does or does not leave the building.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    directoryTaxonomy();
});

/**
 * The two taxonomy rows these tests need.
 *
 * The real taxonomy is 419 ISIC classes loaded by an artisan command rather
 * than a seeder, so RefreshDatabase leaves the table empty and a sector page
 * has no name to show. Loading all 419 per test would spend a minute proving
 * nothing; these are the rows enumeratedShop's sector code actually points at.
 */
function directoryTaxonomy(): void
{
    DB::table('isic_classes')->updateOrInsert(
        ['code' => '4711'],
        [
            'level' => 'class',
            'parent_code' => '471',
            'name' => 'Retail sale in non-specialized stores with food, beverages or tobacco predominating',
            'description' => 'Provisions shops, kiosks and supermarkets.',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    foreach (['Kiosk', 'Provisions store', 'Mini mart'] as $term) {
        DB::table('trade_aliases')->updateOrInsert(
            ['term' => $term],
            [
                'isic_code' => '4711',
                'weight' => 1,
                'language' => 'en',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}

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

    // Filtered rather than firstWhere'd, so the row keeps its shape all the
    // way to the assertion about its keys.
    $matches = array_values(array_filter(
        $page['results'],
        static fn (array $row): bool => $row['id'] === $shop->id,
    ));

    expect($matches)->toHaveCount(1);

    $row = $matches[0];

    expect($row['depth'])->toBe('reduced')
        ->and($row['tradingName'])->toBe('Signposted Stores');

    // The whole projection, named. A key appearing here that is not in this
    // list is a disclosure somebody added without deciding to.
    expect(array_keys($row))->toEqualCanonicalizing([
        'id', 'depth', 'tradingName', 'sector', 'sectorCode', 'structureType',
        'ward', 'lga', 'tier', 'verified', 'openingHours', 'photos',
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
                'ward', 'lga', 'tier', 'verified', 'openingHours', 'photos',
            ]);
    }
});

/*
| Photographs.
|
| One media table holds an officer's evidence and a business's own shopfront
| photographs, kept apart by a database constraint on authorship. What follows
| is the other half of that: the directory asks for storefront photographs with
| a party author by name, and never gets anything else.
*/

it('shows a photograph the business took of itself, once it has published', function () {
    Storage::fake('local');

    $it = buyerWithShop('Photo Owner', '08039990789');
    $shop = $it['shop'];

    EnterpriseObservation::query()
        ->where('enterprise_id', $shop->id)
        ->update(['signage_observed' => true]);

    app(PublishStorefrontPhoto::class)(
        UploadedFile::fake()->image('shopfront.jpg', 1200, 800),
        $shop,
        $it['party'],
        $it['account'],
        (string) Str::uuid7(),
    );

    // Not yet: a photograph is not a publication decision. Until the owner
    // opts in there is no listing to put it on.
    expect(collect(app(SearchDirectory::class)->run()['results'])->firstWhere('id', $shop->id))
        ->toBeNull();

    app(SetPublicationState::class)(
        $it['party'],
        $it['account'],
        $shop->refresh(),
        PublicationState::OptedIn,
    );

    $row = collect(app(SearchDirectory::class)->run()['results'])->firstWhere('id', $shop->id);

    expect($row['photos'])->toHaveCount(1)
        ->and($row['photos'][0]['url'])->toContain('/media/file/')
        // The whole photo projection: a URL and nothing else.
        ->and(array_keys($row['photos'][0]))->toBe(['url']);
});

it('never puts an officer photograph in the directory', function () {
    Storage::fake('local');

    $it = buyerWithShop('Evidence Owner', '08039990790');
    $shop = $it['shop'];

    EnterpriseObservation::query()
        ->where('enterprise_id', $shop->id)
        ->update(['signage_observed' => true]);

    // An officer's facade shot, attached to the same business. It is evidence
    // of a visit and it may show an interior, a neighbour or a passer-by who
    // consented to nothing.
    Media::query()->create([
        'mediable_type' => $shop->getMorphClass(),
        'mediable_id' => $shop->id,
        'kind' => Media::KIND_FACADE,
        'disk' => 'local',
        'disk_path' => 'evidence/officer-facade.jpg',
        'sha256' => str_repeat('a', 64),
        'bytes' => 1024,
        'captured_by' => person(Role::Officer, 'Evidence Officer')->id,
        'captured_at' => now(),
        'status' => Media::STATUS_STORED,
        'client_uuid' => (string) Str::uuid7(),
    ]);

    app(SetPublicationState::class)(
        $it['party'],
        $it['account'],
        $shop->refresh(),
        PublicationState::OptedIn,
    );

    $row = collect(app(SearchDirectory::class)->run()['results'])->firstWhere('id', $shop->id);

    expect($row['photos'])->toBe([]);
});

it('stops showing a photograph the business takes down, without deleting it', function () {
    Storage::fake('local');

    $it = buyerWithShop('Withdrawing Owner', '08039990791');
    $shop = $it['shop'];

    EnterpriseObservation::query()
        ->where('enterprise_id', $shop->id)
        ->update(['signage_observed' => true]);

    $publish = app(PublishStorefrontPhoto::class);

    $photo = $publish(
        UploadedFile::fake()->image('front.jpg', 900, 600),
        $shop,
        $it['party'],
        $it['account'],
        (string) Str::uuid7(),
    );

    app(SetPublicationState::class)(
        $it['party'],
        $it['account'],
        $shop->refresh(),
        PublicationState::OptedIn,
    );

    $publish->withdraw($photo, $it['party'], $it['account']);

    $row = collect(app(SearchDirectory::class)->run()['results'])->firstWhere('id', $shop->id);

    expect($row['photos'])->toBe([])
        // Withdrawn, not deleted: what this listing showed last March is still
        // an answerable question.
        ->and(Media::query()->whereKey($photo->id)->exists())->toBeTrue()
        ->and($photo->refresh()->status)->toBe(Media::STATUS_WITHDRAWN);
});

it('refuses a photograph from somebody who does not manage the business', function () {
    Storage::fake('local');

    $it = buyerWithShop('Real Owner', '08039990792');
    $stranger = claimant('Passing Stranger', '08039990793');

    $this->actingAs($stranger['account'], 'portal')
        ->post("/portal/businesses/{$it['shop']->id}/photos", [
            'photo' => UploadedFile::fake()->image('not-mine.jpg'),
        ])
        ->assertForbidden();

    expect(PublishStorefrontPhoto::countFor($it['shop']))->toBe(0);
});

/*
| Sector pages and suggestions.
|
| Both are new ways into the same population, which is the thing to hold: a
| count that disagrees with the list, or a suggestion that carries a field the
| list would have withheld, is the failure worth testing for.
*/

it('counts a sector over exactly the businesses the directory shows', function () {
    $ground = sweptGround();

    $shown = signpostedShop('Counted Provisions', $ground);
    $hidden = enumeratedShop('Hidden Provisions', '0803 123 4567', $ground);

    // Same sector, no signage, nobody's claim: on the register, out of the
    // directory, and it must be out of the count too.
    EnterpriseObservation::query()
        ->where('enterprise_id', $hidden->id)
        ->update(['signage_observed' => false]);

    $sector = app(ReadDirectorySectors::class)->one((string) $shown->sector_code);

    $rows = app(SearchDirectory::class)->run(sector: (string) $shown->sector_code);

    expect($sector)->not->toBeNull()
        ->and($sector['count'])->toBe($rows['total'])
        ->and($sector['count'])->toBeGreaterThanOrEqual(1);

    $names = array_column($rows['results'], 'tradingName');

    expect($names)->toContain('Counted Provisions')
        ->and($names)->not->toContain('Hidden Provisions');
});

it('accounts for every business the directory shows, sector or not', function () {
    $ground = sweptGround();

    signpostedShop('Classified Provisions', $ground);

    // A business that registered itself and never picked a trade. It is in the
    // directory and belongs under no sector heading.
    $unclassified = signpostedShop('Trade Not Recorded', $ground);
    $unclassified->update(['sector_code' => null]);

    $reader = app(ReadDirectorySectors::class);

    $bySector = array_sum(array_column($reader->all(), 'count'));
    $total = app(SearchDirectory::class)->run()['total'];

    // The sector page and the directory have to reconcile, and the difference
    // is exactly the businesses with no trade on record.
    expect($reader->unclassified())->toBeGreaterThanOrEqual(1)
        ->and($bySector + $reader->unclassified())->toBe($total);
});

it('folds a thinly held ward into a total rather than naming it', function () {
    $shop = signpostedShop('Lonely Ward Stores');

    $sector = app(ReadDirectorySectors::class)->one((string) $shop->sector_code);

    // One business in a ward is a name, not a place. It counts, and it is not
    // listed under the ward that would identify it.
    expect($sector['wards'])->toBe([])
        ->and($sector['elsewhere'])->toBeGreaterThanOrEqual(1);

    // And the breakdown adds up, which is the property a reader relies on:
    // anything not listed by ward is in the elsewhere total, including a
    // business whose ward never resolved.
    $listed = array_sum(array_column($sector['wards'], 'count'));

    expect($listed + $sector['elsewhere'])->toBe($sector['count']);
});

it('suggests other businesses of the same trade nearby', function () {
    $ground = sweptGround();

    $one = signpostedShop('First Of Its Kind', $ground);
    $two = signpostedShop('Second Of Its Kind', $ground);

    $similar = app(SearchDirectory::class)->similarTo(
        $one->id,
        (string) $one->sector_code,
        $one->structure->lga?->name,
    );

    $names = array_column($similar, 'tradingName');

    expect($names)->toContain('Second Of Its Kind')
        // Never the business being looked at.
        ->and($names)->not->toContain('First Of Its Kind');

    // And a suggestion is a directory row, with nothing extra on it.
    expect(array_keys($similar[0]))->toEqualCanonicalizing([
        'id', 'depth', 'tradingName', 'sector', 'sectorCode', 'structureType',
        'ward', 'lga', 'tier', 'verified', 'openingHours', 'photos',
    ]);
});

it('reads a colloquial search term back to a trade', function () {
    // trade_aliases exists for this: "kiosk" is what somebody types and 4711
    // is what the taxonomy calls it.
    $meant = app(SearchDirectory::class)->sectorsMeaning('kiosk');

    expect($meant)->not->toBeEmpty()
        ->and(array_column($meant, 'code'))->toContain('4711');
});

it('serves a sector page to anybody, and lets it be indexed', function () {
    $shop = signpostedShop('Sector Page Stores');

    $this->get("/directory/sectors/{$shop->sector_code}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('public/Sector')
            ->where('sector.code', $shop->sector_code))
        // A sector page aggregates what is already public and names nobody who
        // is not already listed, so it is findable.
        ->assertDontSee('noindex', false);

    $this->get('/directory/sectors')->assertOk();
});
