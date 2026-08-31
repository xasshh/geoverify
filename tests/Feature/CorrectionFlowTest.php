<?php

declare(strict_types=1);

use App\Domain\Claim\Actions\GrantControl;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Actions\DecideCorrection;
use App\Domain\Registry\Actions\ProposeCorrection;
use App\Domain\Registry\Actions\ResolveListingTier;
use App\Domain\Registry\Actions\SetPublicationState;
use App\Domain\Registry\Enums\CorrectableField;
use App\Domain\Registry\Enums\CorrectionStatus;
use App\Domain\Registry\Enums\PublicationState;
use App\Domain\Registry\Models\CorrectionProposal;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\EnterpriseObservation;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;

/**
 * M5: a business tells us we have something wrong about it.
 *
 * The rule the whole milestone turns on is that nothing a party says overwrites
 * what an officer observed. A correction is a proposal, a person rules on it,
 * and an accepted one is appended beside the original rather than over it. A
 * register a business can edit is a directory, and a directory is not what
 * anybody is paying for.
 */

/**
 * A shop an officer recorded, now controlled by a party who claimed it.
 *
 * @return array{party: Party, account: PortalAccount, membership: PartyUser, shop: Enterprise}
 */
function shopControlledBy(string $name, string $phone, string $tradingName = 'Mama Ngozi Provisions'): array
{
    $shop = enumeratedShop($tradingName);
    $who = claimant($name, $phone);

    $claim = submitClaimFor($who, $shop);
    app(GrantControl::class)->grant($claim);

    return [...$who, 'shop' => $shop->refresh()];
}

it('records a proposal and changes nothing in the register', function () {
    $it = shopControlledBy('Ngozi Eze', '08031110001');

    $proposal = app(ProposeCorrection::class)(
        $it['party'],
        $it['account'],
        $it['shop'],
        CorrectableField::TradingName,
        'Mama Ngozi Stores',
        'We changed the name last year and the sign is new.',
    );

    expect($proposal->status)->toBe(CorrectionStatus::Submitted)
        // Stamped at proposal time, so a supervisor ruling in October sees what
        // the party was looking at in August.
        ->and($proposal->current_value)->toBe('Mama Ngozi Provisions')
        // And the register is untouched until somebody rules.
        ->and($it['shop']->refresh()->trading_name)->toBe('Mama Ngozi Provisions');

    expect(VerificationEvent::query()->where('event', 'correction.proposed')->exists())->toBeTrue();
});

it('keeps the officer\'s observation when a correction is accepted', function () {
    $it = shopControlledBy('Ngozi Eze', '08031110002');
    $supervisor = person(Role::Supervisor);

    $before = EnterpriseObservation::query()
        ->where('enterprise_id', $it['shop']->id)
        ->orderBy('observed_at')
        ->first();

    $proposal = app(ProposeCorrection::class)(
        $it['party'], $it['account'], $it['shop'],
        CorrectableField::TradingName,
        'Mama Ngozi Stores',
        'The name changed last year.',
    );

    app(DecideCorrection::class)(
        $proposal, $supervisor, CorrectionStatus::Accepted, 'Signage photograph matches the new name.',
    );

    $observations = EnterpriseObservation::query()
        ->where('enterprise_id', $it['shop']->id)
        ->orderBy('observed_at')
        ->get();

    expect($observations)->toHaveCount(2)
        // March stays exactly as March was recorded.
        ->and($observations->first()->trading_name)->toBe('Mama Ngozi Provisions')
        ->and($observations->first()->id)->toBe($before->id)
        ->and($observations->first()->captured_by)->not->toBeNull()
        // And the party's account sits beside it, authored by the party.
        ->and($observations->last()->trading_name)->toBe('Mama Ngozi Stores')
        ->and($observations->last()->captured_by)->toBeNull()
        ->and($observations->last()->recorded_by_party_id)->toBe($it['party']->id);

    // The projection follows, so ordinary reads stay simple.
    expect($it['shop']->refresh()->trading_name)->toBe('Mama Ngozi Stores');
});

it('moves nothing when a correction is rejected', function () {
    $it = shopControlledBy('Ngozi Eze', '08031110003');

    $proposal = app(ProposeCorrection::class)(
        $it['party'], $it['account'], $it['shop'],
        CorrectableField::TradingName, 'Something Else', 'Please change it.',
    );

    app(DecideCorrection::class)(
        $proposal, person(Role::Supervisor), CorrectionStatus::Rejected,
        'The signage in the photograph still reads Provisions.',
    );

    expect($it['shop']->refresh()->trading_name)->toBe('Mama Ngozi Provisions')
        ->and(EnterpriseObservation::query()->where('enterprise_id', $it['shop']->id)->count())->toBe(1)
        ->and($proposal->refresh()->status)->toBe(CorrectionStatus::Rejected);
});

it('does not append an observation for a placement correction', function () {
    $it = shopControlledBy('Ngozi Eze', '08031110004');

    $proposal = app(ProposeCorrection::class)(
        $it['party'], $it['account'], $it['shop'],
        CorrectableField::Floor, '2', 'We are on the second floor, not the ground.',
    );

    app(DecideCorrection::class)(
        $proposal, person(Role::Supervisor), CorrectionStatus::Accepted, 'Confirmed with the caretaker.',
    );

    // Which unit a business occupies is a fact about the record, not about a
    // morning, so nothing is appended and the enterprise alone moves.
    expect(EnterpriseObservation::query()->where('enterprise_id', $it['shop']->id)->count())->toBe(1)
        ->and($it['shop']->refresh()->floor)->toBe(2);
});

it('refuses a correction from a party that does not manage the listing', function () {
    $it = shopControlledBy('Ngozi Eze', '08031110005');
    $stranger = claimant('Someone Else', '08031110006');

    expect(fn () => app(ProposeCorrection::class)(
        $stranger['party'], $stranger['account'], $it['shop'],
        CorrectableField::TradingName, 'My Shop Now', 'It is mine.',
    ))->toThrow(RuntimeException::class, 'do not manage this business');
});

it('keeps one live proposal per field', function () {
    $it = shopControlledBy('Ngozi Eze', '08031110007');
    $propose = app(ProposeCorrection::class);

    $propose($it['party'], $it['account'], $it['shop'], CorrectableField::TradingName, 'First', 'Reason one.');

    expect(fn () => $propose(
        $it['party'], $it['account'], $it['shop'], CorrectableField::TradingName, 'Second', 'Reason two.',
    ))->toThrow(RuntimeException::class, 'already have a correction waiting');

    // A different field is a different request and is allowed.
    $propose($it['party'], $it['account'], $it['shop'], CorrectableField::Phone, '08030000000', 'New line.');

    expect(CorrectionProposal::query()->live()->count())->toBe(2);
});

it('refuses a correction that proposes what the register already says', function () {
    $it = shopControlledBy('Ngozi Eze', '08031110008');

    expect(fn () => app(ProposeCorrection::class)(
        $it['party'], $it['account'], $it['shop'],
        CorrectableField::TradingName, 'Mama Ngozi Provisions', 'It is wrong.',
    ))->toThrow(RuntimeException::class, 'already what the register says');
});

it('requires a written reason on both sides of the decision', function () {
    $it = shopControlledBy('Ngozi Eze', '08031110009');

    expect(fn () => app(ProposeCorrection::class)(
        $it['party'], $it['account'], $it['shop'],
        CorrectableField::TradingName, 'New Name', '   ',
    ))->toThrow(RuntimeException::class, 'Say what is wrong');

    $proposal = app(ProposeCorrection::class)(
        $it['party'], $it['account'], $it['shop'],
        CorrectableField::TradingName, 'New Name', 'The sign changed.',
    );

    // An acceptance with no reason is a change to the register nobody signed.
    expect(fn () => app(DecideCorrection::class)(
        $proposal, person(Role::Supervisor), CorrectionStatus::Accepted, '',
    ))->toThrow(RuntimeException::class, 'Say why');
});

it('lets nobody but a supervisor decide a correction', function () {
    $it = shopControlledBy('Ngozi Eze', '08031110010');

    $proposal = app(ProposeCorrection::class)(
        $it['party'], $it['account'], $it['shop'],
        CorrectableField::TradingName, 'New Name', 'The sign changed.',
    );

    expect(fn () => app(DecideCorrection::class)(
        $proposal, person(Role::Officer), CorrectionStatus::Accepted, 'Looks fine to me.',
    ))->toThrow(RuntimeException::class, 'Only a supervisor');
});

it('starts every listing private, whoever enumerated it', function () {
    $shop = enumeratedShop('Quiet Shop');

    // Enumeration is not consent to publication. An officer walked up to a
    // shop; nobody asked the owner about a public directory.
    expect($shop->publication_state)->toBe(PublicationState::Private);
});

it('publishes only when a party opts in, and withdraws immediately', function () {
    $it = shopControlledBy('Ngozi Eze', '08031110011');
    $set = app(SetPublicationState::class);

    $set($it['party'], $it['account'], $it['shop'], PublicationState::OptedIn);

    expect($it['shop']->refresh()->publication_state->publishable())->toBeTrue()
        ->and($it['shop']->publication_decided_at)->not->toBeNull();

    $set($it['party'], $it['account'], $it['shop'], PublicationState::Withheld);

    // On the row, at once. No grace period: a party that has changed its mind
    // has changed its mind.
    expect($it['shop']->refresh()->publication_state->publishable())->toBeFalse();

    $events = VerificationEvent::query()
        ->whereIn('event', ['publication.opted_in', 'publication.withheld'])
        ->pluck('event');

    expect($events)->toContain('publication.opted_in')
        ->and($events)->toContain('publication.withheld');
});

it('will not let a party move a listing back to private', function () {
    $it = shopControlledBy('Ngozi Eze', '08031110012');

    // Private means nobody has asked. Withheld means somebody was asked and
    // said no, and the two must not collapse into each other.
    expect(fn () => app(SetPublicationState::class)(
        $it['party'], $it['account'], $it['shop'], PublicationState::Private,
    ))->toThrow(RuntimeException::class, 'Choose whether to publish');
});

it('ages a tier against the configured window', function () {
    $tiers = app(ResolveListingTier::class);
    $read = fn (string $when): array => collect($tiers->rungs('field', 'accepted', now()->parse($when)))
        ->firstWhere('tier', 'location_verified');

    config()->set('geoverify.tier_freshness', ['current_months' => 12, 'stale_months' => 24]);

    expect($read(now()->subMonths(2)->toDateString())['state'])->toBe('current')
        ->and($read(now()->subMonths(15)->toDateString())['state'])->toBe('ageing')
        ->and($read(now()->subMonths(30)->toDateString())['state'])->toBe('stale')
        // Still established, whatever its age. Nothing here expires: a tier
        // that stopped counting would be the register deleting its own evidence.
        ->and($read(now()->subMonths(30)->toDateString())['establishedOn'])->not->toBeEmpty();

    // Configurable, because a market where stalls turn over quarterly and an
    // industrial estate do not age at the same rate.
    config()->set('geoverify.tier_freshness', ['current_months' => 1, 'stale_months' => 3]);

    expect($read(now()->subMonths(2)->toDateString())['state'])->toBe('ageing');
});

it('says how long ago in words a reader does not have to convert', function () {
    $tiers = app(ResolveListingTier::class);
    $elapsed = fn (string $when): string => collect($tiers->rungs('field', 'accepted', now()->parse($when)))
        ->firstWhere('tier', 'location_verified')['elapsed'];

    expect($elapsed(now()->toDateString()))->toBe('this month')
        ->and($elapsed(now()->subMonth()->toDateString()))->toBe('1 month')
        ->and($elapsed(now()->subMonths(7)->toDateString()))->toBe('7 months')
        ->and($elapsed(now()->subMonths(30)->toDateString()))->toBe('2 years');
});
