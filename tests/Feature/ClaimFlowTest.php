<?php

declare(strict_types=1);

use App\Domain\Claim\Actions\ConfirmClaimCode;
use App\Domain\Claim\Actions\DecideClaim;
use App\Domain\Claim\Actions\RequestClaimCode;
use App\Domain\Claim\Actions\ResolveDispute;
use App\Domain\Claim\Actions\SearchRegister;
use App\Domain\Claim\Actions\SubmitClaim;
use App\Domain\Claim\Enums\ClaimEvidence;
use App\Domain\Claim\Enums\ClaimRelationship;
use App\Domain\Claim\Enums\ClaimStatus;
use App\Domain\Claim\Events\ClaimCodeIssued;
use App\Domain\Claim\Models\Claim;
use App\Domain\Claim\Models\ClaimDispute;
use App\Domain\Claim\Models\ClaimPhoneCode;
use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Party\Actions\RegisterParty;
use App\Domain\Party\Enums\PartyKind;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Actions\CaptureEnterprise;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Claims
|--------------------------------------------------------------------------
|
| The milestone that turns a dataset into a user base, and the one with the
| most ways to go wrong. Three things are load bearing and each is proved
| rather than asserted in a comment: control of a listing is exclusive, the
| register does not leak through the search that makes it findable, and an
| officer's observation is not editable by the person it describes.
|
*/

/**
 * One officer, one assigned cell, reused for every shop in a test.
 *
 * Returned by reference so the caller can hand the same ground to several
 * shops and have each land on its own structure.
 *
 * An ArrayObject rather than an array so the placement counter advances for
 * the caller too: three shops on one cell must not land on one another.
 *
 * @return ArrayObject<string, mixed>
 */
function sweptGround(): ArrayObject
{
    $officer = person(Role::Officer, 'Officer '.Str::random(4));
    $cell = assignedCell($officer, person(Role::Supervisor, 'Supervisor '.Str::random(4)));

    return new ArrayObject(['officer' => $officer, 'cell' => $cell, 'placed' => 0]);
}

/**
 * A business in the register, as an officer would have left it.
 *
 * The phone is stored the way a field client stores it, spacing and all,
 * because normalising it here would test a column this code will never meet.
 */
/** @param  ArrayObject<string, mixed>|null  $ground */
function enumeratedShop(string $tradingName, ?string $phone = '0803 123 4567', ?ArrayObject $ground = null): Enterprise
{
    // H3 indexes are unique across the whole grid, so a second mandate over the
    // same polygon generates no cells at all. A test that wants several shops
    // has to put them on one patch of ground, which is also what a real market
    // row looks like.
    $ground ??= sweptGround();

    $officer = $ground['officer'];
    $cell = $ground['cell'];
    $session = sessionFor($officer, $cell);

    // Nudged apart so each shop gets its own structure rather than a second
    // observation of the first one.
    [$lon, $lat] = cellCentre($cell);
    $offset = $ground['placed']++ * 0.00012;

    $observation = captureIn($cell, $officer, $session, now()->subDays(30), $lon + $offset, $lat + $offset);

    $payload = [
        'client_uuid' => (string) Str::uuid7(),
        'observation_uuid' => (string) Str::uuid7(),
        'structure_id' => $observation->structure_id,
        'trading_name' => $tradingName,
        'sector_code' => '4711',
        'scale_band' => 'micro',
        'operating_status' => 'operating',
        'observed_at' => now()->subDays(30)->toIso8601String(),
        'field_session_id' => $session->id,
    ];

    if ($phone !== null) {
        $payload['phone'] = $phone;
    }

    return app(CaptureEnterprise::class)->capture($payload, $officer);
}

/**
 * A party with one account that can act for it.
 *
 * @return array{party: Party, account: PortalAccount, membership: PartyUser}
 */
function claimant(string $name, string $phone): array
{
    $party = app(RegisterParty::class)(
        verifiedPhone: $phone,
        personName: $name,
        displayName: $name,
        kind: PartyKind::Individual,
    );

    $membership = PartyUser::query()->where('party_id', $party->id)->firstOrFail();

    return [
        'party' => $party,
        'account' => PortalAccount::query()->findOrFail($membership->portal_account_id),
        'membership' => $membership,
    ];
}

/** @param  array{party: Party, account: PortalAccount, membership: PartyUser}  $who */
function submitClaimFor(array $who, Enterprise $shop, ?float $lat = null, ?float $lng = null): Claim
{
    return app(SubmitClaim::class)->run(
        $who['party'],
        $who['account'],
        $shop,
        ClaimRelationship::Owner,
        $lat,
        $lng,
    );
}

/** The code that actually went out, read from the issue event. */
function latestClaimCode(): string
{
    $codes = [];

    Event::assertDispatched(ClaimCodeIssued::class, function (ClaimCodeIssued $event) use (&$codes): bool {
        $codes[] = $event;

        return true;
    });

    $last = end($codes);

    if ($last === false) {
        throw new RuntimeException('No claim code was issued.');
    }

    return $last->code;
}

it('finds an enumerated shop by name without exposing anything the searcher has not earned', function () {
    $shop = enumeratedShop('Mama Ngozi Provisions');

    $results = app(SearchRegister::class)->run('mama ngozi');

    expect($results)->toHaveCount(1);

    $found = $results[0];

    expect($found['trading_name'])->toBe('Mama Ngozi Provisions')
        ->and($found['enterprise_id'])->toBe($shop->id)
        ->and($found['tier'])->toBe('location_verified')
        // The tier never travels without its date.
        ->and($found['established_on'])->not->toBeEmpty()
        ->and($found['can_prove_by_phone'])->toBeTrue();

    // The projection is the security boundary. If a column appears here that is
    // not on this list, somebody widened the SELECT and the register started
    // leaking one search at a time.
    expect(array_keys($found))->toEqualCanonicalizing([
        'enterprise_id', 'trading_name', 'structure_type', 'ward', 'lga',
        'tier', 'established_on', 'is_claimed', 'can_prove_by_phone', 'metres_away',
    ]);
});

it('never puts the recorded phone number in a search result', function () {
    enumeratedShop('Rivers Fabrics', '0806 555 1234');

    $results = app(SearchRegister::class)->run('rivers');
    $serialised = json_encode($results);

    expect($serialised)->not->toContain('0806')
        ->and($serialised)->not->toContain('5551234')
        ->and($serialised)->not->toContain('555 1234');
});

it('settles a claim the moment the officer-recorded number answers', function () {
    Event::fake([ClaimCodeIssued::class]);

    $shop = enumeratedShop('Blessing Hardware', '0803 111 2222');
    $who = claimant('Blessing Okafor', '08051112222');

    $claim = submitClaimFor($who, $shop);

    expect($claim->status)->toBe(ClaimStatus::Submitted);

    app(RequestClaimCode::class)($claim, '127.0.0.1');
    $settled = app(ConfirmClaimCode::class)($claim, latestClaimCode());

    expect($settled->status)->toBe(ClaimStatus::Approved)
        ->and($settled->decision)->toBe(Claim::DECISION_PHONE_MATCH)
        // Nobody looked at it. That is the point of this path.
        ->and($settled->decided_by)->toBeNull();

    $control = PartyBusiness::query()
        ->where('enterprise_id', $shop->id)
        ->where('status', PartyBusiness::STATUS_ACTIVE)
        ->first();

    expect($control)->not->toBeNull()
        ->and($control->party_id)->toBe($who['party']->id);
});

it('records the phone confirmation without writing the number into the evidence', function () {
    Event::fake([ClaimCodeIssued::class]);

    $shop = enumeratedShop('Kano Textiles', '0803 444 5555');
    $who = claimant('Musa Ibrahim', '08054445555');
    $claim = submitClaimFor($who, $shop);

    app(RequestClaimCode::class)($claim, '127.0.0.1');
    $settled = app(ConfirmClaimCode::class)($claim, latestClaimCode());

    expect($settled->confirms(ClaimEvidence::PhoneMatch))->toBeTrue()
        ->and(json_encode($settled->evidence))->not->toContain('4445555')
        ->and(json_encode($settled->evidence))->not->toContain('444 5555');

    // Nor into the audit log, which is read by more people than the claim is.
    $events = VerificationEvent::query()
        ->where('subject_id', $shop->id)
        ->get()
        ->map(fn (VerificationEvent $e): string => json_encode($e->evidence) ?: '')
        ->implode(' ');

    expect($events)->not->toContain('4445555');
});

it('matches the recorded number however the officer typed it', function () {
    Event::fake([ClaimCodeIssued::class]);

    $ground = sweptGround();

    // Same number, three ways an officer might have written it down.
    foreach (['0803 777 8888', '+234 8037778888', '08037778888'] as $index => $written) {
        $shop = enumeratedShop('Spelling Test '.$index, $written, $ground);
        $who = claimant('Claimant '.$index, '080'.(11111110 + $index));
        $claim = submitClaimFor($who, $shop);

        app(RequestClaimCode::class)($claim, '127.0.0.1');

        expect(app(ConfirmClaimCode::class)($claim, latestClaimCode())->status)
            ->toBe(ClaimStatus::Approved);
    }
});

it('will not approve on proximity however close the claimant is standing', function () {
    $shop = enumeratedShop('Corner Kiosk', null);
    $who = claimant('Nearby Person', '08052223333');

    // Standing on the doorstep, at the exact recorded position.
    $point = DB::selectOne(
        'SELECT ST_Y(centroid::geometry) lat, ST_X(centroid::geometry) lng FROM structures WHERE id = ?',
        [$shop->structure_id],
    );

    $claim = submitClaimFor($who, $shop, (float) $point->lat, (float) $point->lng);

    expect($claim->status)->toBe(ClaimStatus::Submitted)
        ->and($claim->evidence[ClaimEvidence::Proximity->value]['metres'])->toBe(0)
        ->and(PartyBusiness::query()->where('enterprise_id', $shop->id)->count())->toBe(0);
});

it('opens a dispute rather than a second controller when a listing is already claimed', function () {
    Event::fake([ClaimCodeIssued::class]);

    $shop = enumeratedShop('Contested Stores', '0803 999 0000');
    $first = claimant('First Claimant', '08059990000');
    $second = claimant('Second Claimant', '08050001111');

    $firstClaim = submitClaimFor($first, $shop);
    app(RequestClaimCode::class)($firstClaim, '127.0.0.1');
    app(ConfirmClaimCode::class)($firstClaim, latestClaimCode());

    $secondClaim = submitClaimFor($second, $shop);

    expect($secondClaim->status)->toBe(ClaimStatus::Disputed);

    $dispute = ClaimDispute::query()->where('challenger_claim_id', $secondClaim->id)->first();

    expect($dispute)->not->toBeNull()
        ->and($dispute->isOpen())->toBeTrue();

    // The incumbent keeps trading. A claim must not be a way to take somebody's
    // listing offline.
    $controllers = PartyBusiness::query()
        ->where('enterprise_id', $shop->id)
        ->where('status', PartyBusiness::STATUS_ACTIVE)
        ->get();

    expect($controllers)->toHaveCount(1)
        ->and($controllers[0]->party_id)->toBe($first['party']->id);
});

it('refuses to auto approve a disputed claim even when its phone answers', function () {
    Event::fake([ClaimCodeIssued::class]);

    // The incumbent got in by review; the challenger holds the phone. Strong
    // evidence still does not settle a contested listing on its own.
    $shop = enumeratedShop('Two Owners', '0803 222 4444');
    $incumbent = claimant('Incumbent', '08051110000');
    $challenger = claimant('Challenger', '08052224444');

    $incumbentClaim = submitClaimFor($incumbent, $shop);
    app(DecideClaim::class)
        ->approveByReview($incumbentClaim, person(Role::Supervisor), 'Tenancy agreement sighted.');

    $challengerClaim = submitClaimFor($challenger, $shop);
    app(RequestClaimCode::class)($challengerClaim, '127.0.0.1');
    $after = app(ConfirmClaimCode::class)($challengerClaim, latestClaimCode());

    expect($after->status)->toBe(ClaimStatus::Disputed)
        ->and($after->confirms(ClaimEvidence::PhoneMatch))->toBeTrue()
        ->and(PartyBusiness::query()
            ->where('enterprise_id', $shop->id)
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->value('party_id'))->toBe($incumbent['party']->id);
});

it('transfers control cleanly when a dispute goes the challenger way', function () {
    Event::fake([ClaimCodeIssued::class]);

    $shop = enumeratedShop('Transferred Shop', '0803 333 5555');
    $incumbent = claimant('Old Holder', '08051112223');
    $challenger = claimant('Real Owner', '08053335555');

    $incumbentClaim = submitClaimFor($incumbent, $shop);
    app(DecideClaim::class)
        ->approveByReview($incumbentClaim, person(Role::Supervisor), 'Documents looked sound.');

    $challengerClaim = submitClaimFor($challenger, $shop);
    $dispute = ClaimDispute::query()->where('challenger_claim_id', $challengerClaim->id)->firstOrFail();

    app(ResolveDispute::class)(
        $dispute,
        person(Role::Supervisor, 'Deciding supervisor'),
        ClaimDispute::TRANSFERRED,
        'Challenger produced the CAC certificate and the tenancy.',
    );

    $active = PartyBusiness::query()
        ->where('enterprise_id', $shop->id)
        ->where('status', PartyBusiness::STATUS_ACTIVE)
        ->get();

    expect($active)->toHaveCount(1)
        ->and($active[0]->party_id)->toBe($challenger['party']->id);

    // The old holder's row is revoked, not deleted. Who held this listing and
    // why they stopped is part of what the register knows.
    $old = PartyBusiness::query()
        ->where('enterprise_id', $shop->id)
        ->where('party_id', $incumbent['party']->id)
        ->firstOrFail();

    expect($old->status)->toBe(PartyBusiness::STATUS_REVOKED)
        ->and($old->revoked_at)->not->toBeNull();
});

it('burns a code after five wrong guesses instead of letting them continue', function () {
    Event::fake([ClaimCodeIssued::class]);

    $shop = enumeratedShop('Guarded Shop', '0803 666 7777');
    $who = claimant('Guesser', '08056667777');
    $claim = submitClaimFor($who, $shop);

    app(RequestClaimCode::class)($claim, '127.0.0.1');
    $real = latestClaimCode();
    $wrong = $real === '000000' ? '111111' : '000000';

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        expect(fn () => app(ConfirmClaimCode::class)($claim, $wrong))->toThrow(RuntimeException::class);
    }

    // Even the right code is refused now: the code is spent, not merely slowed.
    expect(fn () => app(ConfirmClaimCode::class)($claim, $real))->toThrow(RuntimeException::class);

    expect($claim->refresh()->status)->toBe(ClaimStatus::Submitted)
        ->and(ClaimPhoneCode::query()->where('claim_id', $claim->id)->value('attempts'))->toBe(5);
});

it('appends every step of a claim to verification_events', function () {
    Event::fake([ClaimCodeIssued::class]);

    $shop = enumeratedShop('Audited Shop', '0803 888 9999');
    $who = claimant('Audited Party', '08058889999');
    $claim = submitClaimFor($who, $shop);

    app(RequestClaimCode::class)($claim, '127.0.0.1');
    app(ConfirmClaimCode::class)($claim, latestClaimCode());

    $events = VerificationEvent::query()
        ->where('subject_type', $shop->getMorphClass())
        ->where('subject_id', $shop->id)
        ->pluck('event')
        ->all();

    expect($events)->toContain('claim.submitted')
        ->toContain('claim.code_sent')
        ->toContain('claim.phone_confirmed')
        ->toContain('claim.control_granted');

    // The party is named on the row, by code. An audit line that says only
    // "a party did this" cannot answer the question an audit log is for.
    $row = VerificationEvent::query()->where('event', 'claim.control_granted')->firstOrFail();

    expect($row->actor_type)->toBe(VerificationEvent::ACTOR_PARTY)
        ->and($row->actor_label)->toBe($who['party']->code);
});
