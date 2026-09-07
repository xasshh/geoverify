<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\RecordConsentReceipt;
use App\Domain\Identity\Models\ConsentReceipt;
use App\Domain\Identity\Models\ProcessingPurpose;
use App\Domain\Registry\Actions\SetPublicationState;
use App\Domain\Registry\Enums\PublicationState;
use App\Domain\Verification\Actions\AssignVerificationVisit;
use App\Domain\Verification\Actions\CompleteOrder;
use App\Domain\Verification\Actions\IssuePublicVerification;
use App\Domain\Verification\Actions\RecordPayment;
use App\Domain\Verification\Actions\ResolvePublicVerification;
use App\Domain\Verification\Enums\OrderOutcome;
use App\Domain\Verification\Exports\AssembleCertificate;
use App\Domain\Verification\Exports\QrCode;
use App\Domain\Verification\Models\PublicVerification;
use App\Domain\Verification\Models\VerificationEvent;
use App\Domain\Verification\Models\VerificationOrder;
use App\Enums\Role;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\URL;

/**
 * M7: the document somebody holds, and the consent behind it.
 *
 * The milestone gate. A completed verification produces a certificate carrying
 * what was found, where, when and to what accuracy, with a code anybody can
 * check and an officer reference that is not the officer. Publication is a
 * consent decision with a receipt, revocation lands at once, and a private
 * record stays private on every surface that does not have a mandate to see it.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
});

/**
 * Takes an order that has been placed all the way through to acceptance.
 *
 * Split from completedOrder() because H3 indexes are unique across the whole
 * grid: a second call to buyerWithShop() in one test lays a second mandate over
 * the same polygon, generates no cells, and fails somewhere that looks nothing
 * like the cause.
 *
 * @param  array<string, mixed>  $it
 * @return array<string, mixed>
 */
function seeOrderThrough(array $it, VerificationOrder $order, OrderOutcome $outcome = OrderOutcome::Confirmed): array
{
    app(RecordPayment::class)($order, $order->reference);

    $supervisor = person(Role::Supervisor, 'Ada Supervisor');
    $officer = person(Role::Officer, 'Emeka Officer');

    app(AssignVerificationVisit::class)($order->refresh(), $officer, $supervisor);

    officerFilesVisit($order->refresh(), $officer);

    $order = app(CompleteOrder::class)($order->refresh(), $supervisor, $outcome);

    return [...$it, 'order' => $order, 'supervisor' => $supervisor, 'officer' => $officer];
}

/**
 * A shop that has been claimed, paid for, visited and accepted.
 *
 * @return array<string, mixed>
 */
function completedOrder(OrderOutcome $outcome = OrderOutcome::Confirmed): array
{
    $it = buyerWithShop();

    return seeOrderThrough($it, placeOrderFor($it), $outcome);
}

it('mints a checkable code when the work is accepted, and only then', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);

    // Paid is not done. The money bought a promise, and a code minted here
    // would be checkable before anybody had been to look.
    app(RecordPayment::class)($order, $order->reference);

    expect(PublicVerification::query()->where('verification_order_id', $order->id)->exists())
        ->toBeFalse();

    $supervisor = person(Role::Supervisor, 'Ada Supervisor');
    $officer = person(Role::Officer, 'Emeka Officer');

    app(AssignVerificationVisit::class)($order->refresh(), $officer, $supervisor);
    officerFilesVisit($order->refresh(), $officer);

    // Filed is not done either: an officer's report is a claim nobody has
    // checked. The code appears when a supervisor accepts the work.
    expect(PublicVerification::query()->where('verification_order_id', $order->id)->exists())
        ->toBeFalse();

    app(CompleteOrder::class)($order->refresh(), $supervisor, OrderOutcome::Confirmed);

    expect(PublicVerification::query()->where('verification_order_id', $order->id)->exists())
        ->toBeTrue();
});

it('does not scatter a second code if an order is somehow completed twice', function () {
    $done = completedOrder();

    app(IssuePublicVerification::class)($done['order']);

    expect(PublicVerification::query()
        ->where('verification_order_id', $done['order']->id)
        ->whereNull('revoked_at')
        ->count())->toBe(1);
});

it('answers a scanned code with the finding, and never with the contact details', function () {
    $done = completedOrder();

    $token = PublicVerification::query()
        ->where('verification_order_id', $done['order']->id)
        ->value('token');

    $answer = app(ResolvePublicVerification::class)($token);

    expect($answer['state'])->toBe('valid')
        ->and($answer['finding'])->toBe('Confirmed at this address')
        ->and($answer['business'])->toBe('Okonkwo Hardware');

    // The projection is the disclosure. Widening it has to be a deliberate act,
    // so the exact key set is asserted rather than a handful of absences.
    expect(array_keys($answer))->toEqualCanonicalizing([
        'state', 'reference', 'business', 'ward', 'lga', 'state_name',
        'finding', 'tier', 'verified_on', 'valid_until', 'freshness',
        'officer_reference',
    ]);
});

it('names the attending officer without naming the officer', function () {
    $done = completedOrder();

    $token = PublicVerification::query()
        ->where('verification_order_id', $done['order']->id)
        ->value('token');

    $answer = app(ResolvePublicVerification::class)($token);
    $officer = $done['officer'];

    expect($answer['officer_reference'])->toStartWith('GV-')
        ->and($answer['officer_reference'])->not->toContain((string) $officer->id)
        ->and(mb_strtolower($answer['officer_reference']))->not->toContain(mb_strtolower($officer->name))
        ->and($answer['officer_reference'])->not->toContain($officer->email);
});

it('tells a stranger nothing about a code that does not exist', function () {
    expect(app(ResolvePublicVerification::class)('nosuchtokenatallwhatsoever'))
        ->toBe(['state' => 'unknown']);
});

it('stops answering for a certificate that has been withdrawn', function () {
    $done = completedOrder();

    $verification = PublicVerification::query()
        ->where('verification_order_id', $done['order']->id)
        ->firstOrFail();

    $verification->update(['revoked_at' => now(), 'revocation_reason' => 'Overturned on appeal']);

    expect(app(ResolvePublicVerification::class)($verification->token))
        ->toBe(['state' => 'revoked']);
});

it('serves the check page to somebody with no account at all', function () {
    $done = completedOrder();

    $token = PublicVerification::query()
        ->where('verification_order_id', $done['order']->id)
        ->value('token');

    $this->get("/verify/{$token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('public/Verify')
            ->where('result.state', 'valid'));
});

it('keeps the certificate check out of search engines', function () {
    $done = completedOrder();

    $token = PublicVerification::query()
        ->where('verification_order_id', $done['order']->id)
        ->value('token');

    $this->get("/verify/{$token}")
        ->assertOk()
        ->assertSee('name="robots" content="noindex, nofollow"', false);
});

it('builds a certificate carrying what was found and how well it was placed', function () {
    $done = completedOrder();

    $certificate = app(AssembleCertificate::class)($done['order']);

    expect($certificate['finding']['outcome'])->toBe('Confirmed at this address')
        ->and($certificate['business']['name'])->toBe('Okonkwo Hardware')
        ->and($certificate['place']['latitude'])->toBeFloat()
        ->and($certificate['place']['longitude'])->toBeFloat()
        ->and($certificate['reference'])->toBe($done['order']->reference)
        ->and($certificate['officer_reference'])->toStartWith('GV-');

    // The hierarchy is carried whether or not this fixture has boundaries
    // loaded. These tests build ground without admin_boundaries, so the
    // resolved names are null here and the document prints "Not resolved":
    // what matters for the gate is that the certificate reads the register's
    // answer rather than inventing one.
    expect($certificate['place'])->toHaveKeys(['ward', 'lga', 'state', 'accuracy_m', 'plus_code']);
});

it('puts a scannable code on the certificate that resolves to the check page', function () {
    $done = completedOrder();

    $certificate = app(AssembleCertificate::class)($done['order']);

    expect($certificate['check']['qr'])->toStartWith('<svg')
        ->and($certificate['check']['qr'])->toContain('<path d="M')
        ->and($certificate['check']['url'])->toContain('/verify/'.$certificate['check']['token']);

    // The code has to carry the URL a phone will open, not a reference somebody
    // then has to type. Proven by re-encoding the URL and comparing matrices,
    // because an SVG that merely looks like a QR code is worthless.
    $expected = app(QrCode::class)->svg($certificate['check']['url'], 150);

    expect($certificate['check']['qr'])->toBe($expected);
});

it('bills a negative finding and still issues a checkable certificate for it', function () {
    $done = completedOrder(OrderOutcome::NotFound);

    $token = PublicVerification::query()
        ->where('verification_order_id', $done['order']->id)
        ->value('token');

    $answer = app(ResolvePublicVerification::class)($token);

    // The honest answer is the valuable one. A register that only handed out a
    // checkable code when the news was good would be selling a conclusion.
    expect($answer['state'])->toBe('valid')
        ->and($answer['finding'])->toBe('Not found at this address');
});

it('writes a receipt saying what was agreed, in the words that were shown', function () {
    $it = buyerWithShop();

    app(SetPublicationState::class)(
        $it['party'],
        $it['account'],
        $it['shop'],
        PublicationState::OptedIn,
    );

    $receipt = ConsentReceipt::query()
        ->where('subject_id', $it['shop']->id)
        ->where('granted', true)
        ->firstOrFail();

    $purpose = ProcessingPurpose::named(ProcessingPurpose::PUBLICATION);

    expect($receipt->disclosure)->toBe($purpose->disclosure)
        ->and($receipt->disclosure_version)->toBe($purpose->disclosure_version)
        ->and($receipt->lawful_basis)->toBe('consent')
        ->and($receipt->scope)->toContain('trading_name')
        ->and($receipt->scope)->not->toContain('phone');
});

it('supersedes the receipt rather than editing it when consent is withdrawn', function () {
    $it = buyerWithShop();
    $set = app(SetPublicationState::class);

    $set($it['party'], $it['account'], $it['shop'], PublicationState::OptedIn);

    $granted = ConsentReceipt::query()->where('granted', true)->firstOrFail();
    $wording = $granted->disclosure;

    $set($it['party'], $it['account'], $it['shop']->refresh(), PublicationState::Withheld);

    $granted->refresh();

    expect($granted->withdrawn_at)->not->toBeNull()
        ->and($granted->disclosure)->toBe($wording)
        ->and($granted->granted)->toBeTrue();

    $withdrawal = ConsentReceipt::query()->findOrFail($granted->withdrawn_by_receipt_id);

    expect($withdrawal->granted)->toBeFalse()
        ->and($withdrawal->disclosure)->toBe($wording);
});

it('will not let a consent receipt be rewritten or removed', function () {
    $it = buyerWithShop();

    $receipt = app(RecordConsentReceipt::class)(
        $it['shop'],
        ProcessingPurpose::PUBLICATION,
        true,
        VerificationEvent::ACTOR_PARTY,
        $it['account']->id,
        'Test',
    );

    expect(fn () => $receipt->update(['disclosure' => 'something else']))
        ->toThrow(QueryException::class);

    expect(fn () => $receipt->delete())->toThrow(QueryException::class);
});

it('takes publication down the moment it is withdrawn', function () {
    $it = buyerWithShop();
    $set = app(SetPublicationState::class);

    $set($it['party'], $it['account'], $it['shop'], PublicationState::OptedIn);

    expect($it['shop']->refresh()->publication_state->publishable())->toBeTrue();

    $set($it['party'], $it['account'], $it['shop']->refresh(), PublicationState::Withheld);

    // On the row, now. Not on a queue, not on the next rebuild of a projection:
    // a party that has changed its mind has changed its mind.
    expect($it['shop']->refresh()->publication_state->publishable())->toBeFalse();
});

it('logs both directions of a publication decision', function () {
    $it = buyerWithShop();
    $set = app(SetPublicationState::class);

    $set($it['party'], $it['account'], $it['shop'], PublicationState::OptedIn);
    $set($it['party'], $it['account'], $it['shop']->refresh(), PublicationState::Withheld);

    $events = VerificationEvent::query()
        ->where('subject_type', $it['shop']->getMorphClass())
        ->where('subject_id', $it['shop']->id)
        ->pluck('event');

    expect($events)->toContain('publication.opted_in')
        ->and($events)->toContain('publication.withheld')
        ->and($events)->toContain('consent.granted')
        ->and($events)->toContain('consent.withdrawn');
});

it('refuses the certificate to a party that does not manage the business', function () {
    $done = completedOrder();
    $stranger = claimant('Aisha Bello', '08039990777');

    $this->actingAs($stranger['account'], 'portal')
        ->get("/portal/orders/{$done['order']->id}/certificate.pdf")
        ->assertNotFound();
});

it('refuses to render a certificate without a signature', function () {
    $done = completedOrder();

    $this->get("/portal/orders/{$done['order']->id}/certificate.html")
        ->assertForbidden();
});

it('has no certificate for an order nobody has completed', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);

    expect(fn () => app(AssembleCertificate::class)($order))
        ->toThrow(RuntimeException::class);
});

/*
| The receipt as a document somebody can hold.
|
| The Act gives a person a copy of what they agreed to. A copy that only exists
| in a table is a copy we have; these prove it is one they have.
*/

it('shows somebody their receipt with no account at all', function () {
    $it = buyerWithShop();

    app(SetPublicationState::class)(
        $it['party'],
        $it['account'],
        $it['shop'],
        PublicationState::OptedIn,
    );

    $receipt = ConsentReceipt::query()->where('granted', true)->firstOrFail();
    $purpose = ProcessingPurpose::named(ProcessingPurpose::PUBLICATION);

    // No guard, no session, no portal account. The token is the whole
    // authorisation, exactly as it is for a certificate check.
    $this->get("/receipts/{$receipt->token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('public/Receipt')
            ->where('receipt.state', 'standing')
            ->where('receipt.subject', $it['shop']->trading_name)
            // The wording as shown, verbatim. This is the assertion the whole
            // table exists for: a receipt that paraphrased would be evidence
            // of nothing.
            ->where('receipt.disclosure', $purpose->disclosure)
            ->where('receipt.disclosure_version', $purpose->disclosure_version));
});

it('says plainly that a withdrawn agreement was withdrawn', function () {
    $it = buyerWithShop();
    $set = app(SetPublicationState::class);

    $set($it['party'], $it['account'], $it['shop'], PublicationState::OptedIn);

    $granted = ConsentReceipt::query()->where('granted', true)->firstOrFail();

    $set($it['party'], $it['account'], $it['shop']->refresh(), PublicationState::Withheld);

    // The original still opens, and now says it was taken back. Both halves of
    // the history are readable, from the link the person was given first.
    $this->get("/receipts/{$granted->token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('receipt.state', 'withdrawn'));

    $withdrawal = ConsentReceipt::query()->findOrFail($granted->refresh()->withdrawn_by_receipt_id);

    // And the withdrawal is not a refusal. Both carry granted false, they are
    // not the same event, and a page telling somebody they declined something
    // they actually agreed to and later withdrew would be wrong about them.
    $this->get("/receipts/{$withdrawal->token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('receipt.state', 'withdrawal'));
});

it('tells a stranger nothing about a receipt token that does not exist', function () {
    $this->get('/receipts/'.str_repeat('a', 48))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('public/Receipt')
            ->where('receipt.state', 'unknown')
            ->missing('receipt.disclosure'));

    // Anything that is not shaped like a token never reaches the action at
    // all. The constraint sits on the group, so all three routes inherit it.
    $this->get('/receipts/../../etc/passwd')->assertNotFound();
    $this->get('/receipts/NOT-A-TOKEN')->assertNotFound();
});

it('keeps a consent receipt out of search engines', function () {
    $it = buyerWithShop();

    app(SetPublicationState::class)(
        $it['party'],
        $it['account'],
        $it['shop'],
        PublicationState::OptedIn,
    );

    $receipt = ConsentReceipt::query()->where('granted', true)->firstOrFail();

    // One person's document on a URL nobody else should hold. Indexing it
    // would publish the agreement and the token that opens it together.
    $this->get("/receipts/{$receipt->token}")
        ->assertOk()
        ->assertSee('noindex', false);
});

it('prints the receipt with the wording that was shown, on this host only', function () {
    $it = buyerWithShop();

    app(SetPublicationState::class)(
        $it['party'],
        $it['account'],
        $it['shop'],
        PublicationState::OptedIn,
    );

    $receipt = ConsentReceipt::query()->where('granted', true)->firstOrFail();
    $purpose = ProcessingPurpose::named(ProcessingPurpose::PUBLICATION);

    $url = URL::temporarySignedRoute(
        'receipts.render',
        now()->addMinutes(2),
        ['token' => $receipt->token],
        absolute: false,
    );

    // withoutVite because this asserts the document, not the stylesheet. The
    // page the browser actually prints does carry the built CSS, and CI builds
    // assets before it runs any of this.
    $this->withoutVite()
        ->get($url)
        ->assertOk()
        ->assertSee($purpose->disclosure, false);

    // A signature that leaked is still useless off the loopback interface: the
    // only thing that should ever fetch this is the browser we started.
    $this->withoutVite()
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get($url)
        ->assertForbidden();
});

it('refuses to render the printable receipt without a signature', function () {
    $it = buyerWithShop();

    app(SetPublicationState::class)(
        $it['party'],
        $it['account'],
        $it['shop'],
        PublicationState::OptedIn,
    );

    $receipt = ConsentReceipt::query()->where('granted', true)->firstOrFail();

    $this->get("/receipts/{$receipt->token}/copy.html")
        ->assertForbidden();
});

it('hands the party the link to its own receipt, and only its own', function () {
    $it = buyerWithShop();

    app(SetPublicationState::class)(
        $it['party'],
        $it['account'],
        $it['shop'],
        PublicationState::OptedIn,
    );

    $receipt = ConsentReceipt::query()->where('granted', true)->firstOrFail();

    // The token appears on the listing because that page is already behind the
    // check that this party controls this business, and handing somebody their
    // own receipt is the point of keeping one.
    $this->actingAs($it['account'], 'portal')
        ->get("/portal/businesses/{$it['shop']->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('publication.receipts.0.token', $receipt->token)
            ->where('publication.receipts.0.granted', true));
});

it('offers the certificate once the work is accepted, and not before', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);

    $this->actingAs($it['account'], 'portal')
        ->get("/portal/orders/{$order->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('certificate', false));

    $done = seeOrderThrough($it, $order);

    $this->actingAs($it['account'], 'portal')
        ->get("/portal/orders/{$done['order']->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('certificate', true));
});

it('keeps a private listing off every surface a stranger can reach', function () {
    $it = buyerWithShop();

    expect($it['shop']->publication_state)->toBe(PublicationState::Private);

    // The portal listing is the closest thing to a public page that exists for
    // a business, and it is not one: it belongs to the party that manages it.
    $stranger = claimant('Ngozi Eze', '08039990888');

    $this->actingAs($stranger['account'], 'portal')
        ->get("/portal/businesses/{$it['shop']->id}")
        ->assertForbidden();

    // A guest is refused rather than redirected, the same as a stranger with an
    // account. Either way the private record does not leave the building.
    $this->get("/portal/businesses/{$it['shop']->id}")
        ->assertForbidden();
});
