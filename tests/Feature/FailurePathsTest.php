<?php

declare(strict_types=1);

use App\Domain\Claim\Actions\ResolveDispute;
use App\Domain\Claim\Models\ClaimDispute;
use App\Domain\Identity\Models\ConsentReceipt;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Registry\Actions\SetPublicationState;
use App\Domain\Registry\Enums\PublicationState;
use App\Domain\Verification\Actions\AssignVerificationVisit;
use App\Domain\Verification\Actions\CompleteOrder;
use App\Domain\Verification\Actions\RecordPayment;
use App\Domain\Verification\Actions\ResolvePublicVerification;
use App\Domain\Verification\Enums\OrderOutcome;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Models\PublicVerification;
use App\Domain\Verification\Models\VerificationOrder;
use App\Enums\Role;
use Database\Seeders\VerificationPricingSeeder;

/**
 * M8: what happens when things go wrong in the wrong order.
 *
 * Each milestone tests its own happy path and its own obvious failure. This
 * file is for the ones that belong to no single milestone because they only
 * appear when two of them meet: a webhook that arrives after the visit, a
 * refund racing an acceptance, a listing that changes hands after somebody
 * has already paid for a certificate on it.
 *
 * The simple cases are not repeated here. Webhook replay under one event id
 * and under a fresh one, the inert return URL and the ordinary breach refund
 * all live in VerificationMarketplaceTest, where they were written.
 *
 * Every assertion below is about money or control, and both are things this
 * system is not allowed to be approximately right about.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
});

/**
 * Every ledger movement touching an order, by reason.
 *
 * @return list<string>
 */
function ledgerReasons(VerificationOrder $order): array
{
    return LedgerEntry::query()
        ->where('verification_order_id', $order->id)
        ->orderBy('id')
        ->pluck('reason')
        ->all();
}

/** What the register believes it is holding for a customer, in kobo. */
function heldFor(VerificationOrder $order): int
{
    return (int) LedgerEntry::query()
        ->where('verification_order_id', $order->id)
        ->whereHas('account', fn ($q) => $q->where('code', LedgerAccount::CUSTOMER_FUNDS_HELD))
        ->sum('amount_minor');
}

it('ignores a charge redelivered after the visit has been accepted', function () {
    $done = completedOrder();
    $order = $done['order'];

    $before = ledgerReasons($order);

    // A provider that did not hear a prompt 200 will try again, and it does not
    // know or care that a supervisor has since accepted the work. The order has
    // left awaiting_payment, so the money is recorded once and the redelivery
    // changes nothing.
    $delivery = paystackDelivery($order, chargeId: 987654);

    $this->call('POST', '/webhooks/paystack', [], [], [], [
        'HTTP_X_PAYSTACK_SIGNATURE' => $delivery['signature'],
        'CONTENT_TYPE' => 'application/json',
    ], $delivery['raw'])->assertOk();

    expect(ledgerReasons($order->refresh()))->toBe($before)
        ->and($order->status)->toBe(OrderStatus::Completed);
});

it('never sweeps an order nobody paid for', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);

    // Abandoned at the payment page, and left there. due_by is set from
    // payment, so an unpaid order has no promise to breach: the sweep must not
    // reach for it, because refunding money that never arrived would post a
    // movement against cash we never received.
    $order->update(['due_by' => now()->subMonth()->toDateString()]);

    $this->artisan('orders:sweep-sla')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and(ledgerReasons($order))->toBe([]);
});

it('refuses to recognise income on an order the sweep has already refunded', function () {
    $it = buyerWithShop();
    $order = placeOrderFor($it);

    app(RecordPayment::class)($order, $order->reference);

    $supervisor = person(Role::Supervisor, 'Ada Supervisor');
    $officer = person(Role::Officer, 'Emeka Officer');

    app(AssignVerificationVisit::class)($order->refresh(), $officer, $supervisor);
    officerFilesVisit($order->refresh(), $officer);

    // The morning sweep runs before anybody gets to the review queue.
    $order->refresh()->update(['due_by' => now()->subDay()->toDateString()]);
    $this->artisan('orders:sweep-sla')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded);

    // And now a supervisor accepts the work, which did happen: the officer
    // went. The visit stands as a capture, but the fee does not come back to
    // life, because the money has already gone home.
    expect(fn () => app(CompleteOrder::class)($order->refresh(), $supervisor, OrderOutcome::Confirmed))
        ->toThrow(RuntimeException::class);

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(heldFor($order))->toBe(0)
        ->and(ledgerReasons($order))->not->toContain(LedgerEntry::REASON_WORK_COMPLETED);
});

it('does not refund an order a supervisor accepted this morning', function () {
    $done = completedOrder();
    $order = $done['order'];

    // Accepted on the day it was due, and the sweep runs at seven the next
    // morning against a date that has now passed. A completed order is out of
    // scope by status rather than by timing, which is the only way that holds.
    $order->update(['due_by' => now()->subDay()->toDateString()]);

    $this->artisan('orders:sweep-sla')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and(ledgerReasons($order))->not->toContain(LedgerEntry::REASON_REFUNDED);
});

it('moves the certificate with the listing when a dispute transfers control', function () {
    $done = completedOrder();
    $order = $done['order'];
    $shop = $done['shop'];

    $token = PublicVerification::query()
        ->where('verification_order_id', $order->id)
        ->value('token');

    // Somebody else turns up with better documents and wins the business.
    $challenger = claimant('Real Owner', '08053335559');
    $challengerClaim = submitClaimFor($challenger, $shop);

    $dispute = ClaimDispute::query()->where('challenger_claim_id', $challengerClaim->id)->firstOrFail();

    app(ResolveDispute::class)(
        $dispute,
        person(Role::Supervisor, 'Deciding supervisor'),
        ClaimDispute::TRANSFERRED,
        'Challenger produced the CAC certificate and the tenancy.',
    );

    // The party that paid can no longer print the certificate, because it no
    // longer holds the business the certificate is about. Refused as 404: a
    // former holder probing order ids should learn nothing either.
    $this->actingAs($done['account'], 'portal')
        ->get("/portal/orders/{$order->id}/certificate.pdf")
        ->assertNotFound();

    // And the code on the copy already printed still answers. The visit
    // happened, an officer stood there, and a change of ownership afterwards
    // does not make that untrue: revoking is a separate decision about a
    // document issued in error, and nobody has made it.
    expect(app(ResolvePublicVerification::class)((string) $token)['state'])->toBe('valid');
});

it('leaves a paid visit running when publication consent is withdrawn under it', function () {
    $it = buyerWithShop();
    $set = app(SetPublicationState::class);

    $set($it['party'], $it['account'], $it['shop'], PublicationState::OptedIn);

    $order = placeOrderFor($it);
    app(RecordPayment::class)($order, $order->reference);

    // The party changes its mind about the directory while an officer is on
    // the way. Two different agreements: the directory is consent and can be
    // taken back at any moment, the visit is a contract that has been paid for.
    $set($it['party'], $it['account'], $it['shop']->refresh(), PublicationState::Withheld);

    expect($it['shop']->refresh()->publication_state)->toBe(PublicationState::Withheld)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    $done = seeOrderThrough($it, $order->refresh());

    // The certificate is still issued and still checkable. A business absent
    // from the directory that bought a document to show people has not stopped
    // wanting to show it, and the two switches were never the same switch.
    $token = PublicVerification::query()
        ->where('verification_order_id', $done['order']->id)
        ->value('token');

    expect(app(ResolvePublicVerification::class)((string) $token)['state'])->toBe('valid');

    // The withdrawal is on the record as its own receipt, taken during the
    // order rather than before it.
    expect(ConsentReceipt::query()->where('granted', false)->exists())->toBeTrue();
});
