<?php

declare(strict_types=1);

use App\Domain\Enumerate\Actions\AssembleEnumerateReport;
use App\Domain\Enumerate\Actions\DecideDeskCheck;
use App\Domain\Enumerate\Actions\ManageEnumerateVisits;
use App\Domain\Enumerate\Actions\ManageMonitoring;
use App\Domain\Enumerate\Actions\ManageTickets;
use App\Domain\Enumerate\Actions\ScoreEnumerateRequest;
use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\EnumerateTicket;
use App\Domain\Enumerate\Models\EnumerateTicketMessage;
use App\Domain\Ledger\Actions\ReadLedgerBalances;
use App\Enums\Role;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Enumerate E4: the score, the report and its public check, and support.
 *
 * Worth proving: a score is stamped when a request finishes and does not move
 * afterwards; the report says no more than the requester's page (the outside
 * photographs, never the inside); the QR check says less than the report; and
 * a refund is an administrator's, capped at what has not already gone back.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    config()->set('services.paystack.secret', 'sk_test_marketplace');
    config()->set('services.registry.driver', 'fake');
    config()->set('geoverify.public_holidays', []);
    Storage::fake('local');
    coveredGround();
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00', config('app.timezone')));
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A Tier 2 whose location was confirmed with every check passed. */
function confirmedTier2(): EnumerateRequest
{
    $it = visitAssigned();
    fileVisit($it);
    app(ManageEnumerateVisits::class)->review($it['visit'], $it['supervisor'], true, null);

    return $it['request']->refresh();
}

it('scores a Tier 1 on the registers alone, and stamps it when decided', function () {
    $account = enumerateRequester();
    enumerateFund($account, 5_000);

    $clean = app(DecideDeskCheck::class)(enumerateCheck($account), person(Role::Supervisor), true, null);
    $acme = app(DecideDeskCheck::class)(
        enumerateCheck($account, subject: ['name' => 'ACME VENTURES NIGERIA LIMITED', 'rcNumber' => '1101187', 'companyType' => 'COMPANY']),
        person(Role::Supervisor),
        false,
        'TIN does not match CAC name',
    );

    expect([$clean->refresh()->score, $clean->finding])->toBe([100, 'Registered as described'])
        // CAC matched (20 of 30), FIRS did not.
        ->and([$acme->refresh()->score, $acme->finding])->toBe([67, 'Not as described']);
});

it('scores a Tier 2 on the registers and the visit', function () {
    $request = confirmedTier2();

    expect([$request->score, $request->finding])->toBe([100, 'Operating as described']);
});

it('scores a Tier 3 on every trading day, and the stamp does not move afterwards', function () {
    $it = monitored();

    // Four of six trading days logged open; two nobody went.
    foreach (['2026-10-06', '2026-10-07', '2026-10-08', '2026-10-12'] as $date) {
        logAndAccept($it['request'], $it['officer'], $it['supervisor'], $date);
    }

    onDay('2026-10-13');
    app(ManageMonitoring::class)->close($it['request'], $it['supervisor']);

    $request = $it['request']->refresh();

    // Registry 30 + location 40 + activity 4/6 of 30 = 90.
    expect([$request->score, $request->finding])->toBe([90, 'Operating as described']);

    // A later edit to the scoring inputs does not reach a finished report.
    DB::table('enumerate_visits')->where('enumerate_request_id', $request->id)->where('kind', 'monitoring')->update(['log' => json_encode(['state' => 'closed', 'activity' => 'x'])]);
    expect(app(ScoreEnumerateRequest::class)($request->refresh())['score'])->toBe(90);
});

it('prints the report from the page projection: the outside, never the inside', function () {
    $request = confirmedTier2();
    $url = URL::temporarySignedRoute('enumerate.report.render', now()->addMinutes(2), ['reference' => $request->reference], absolute: false);

    $this->get('/enumerate/reports/'.$request->reference.'/report.html')->assertForbidden();

    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->toContain('Business Verification Report')
        ->toContain($request->reference)
        ->toContain('Operating as described')
        ->toContain('Final')
        ->toContain('<svg')
        ->and(substr_count((string) $html, 'src="data:image/'))->toBe(2)
        ->and($html)->not->toContain('9.0421')
        ->and($html)->not->toContain('Inside ·');

    expect($request->refresh()->report_token)->toMatch('/^[a-z0-9]{40}$/')
        ->and($html)->toContain('/verify/'.$request->report_token);
});

it('answers the QR check with less than the report says', function () {
    $request = confirmedTier2();
    $token = app(AssembleEnumerateReport::class)->token($request);

    $this->get("/verify/{$token}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('public/Verify')
            ->where('result.kind', 'report')
            ->where('result.state', 'valid')
            ->where('result.final', true)
            ->where('result.score', 100)
            ->where('result.finding', 'Operating as described')
            ->where('result.business', 'KORA BUILD SUPPLIES LIMITED')
            ->where('result.ward', 'Garki 1')
            ->missing('result.tin')
            ->missing('result.photos'));

    $this->get('/verify/'.str_repeat('a', 40))->assertInertia(fn ($page) => $page->where('result.state', 'unknown'));
});

it('lets only the wallet that paid download its report', function () {
    $request = confirmedTier2();

    $this->actingAs(enumerateRequester('Somebody Else', '08037770007'), 'portal')
        ->get("/enumerate/verifications/{$request->reference}/report.pdf")
        ->assertNotFound();
});

it('opens a complaint about one of the requester’s own verifications, and answers it', function () {
    $request = confirmedTier2();
    $account = $request->requester()->firstOrFail();
    $tickets = app(ManageTickets::class);

    expect(fn () => $tickets->open(enumerateRequester('Stranger', '08035550005'), $request->reference, 'report_quality', 'Blurred photo', 'The storefront photo is blurred.'))
        ->toThrow(RuntimeException::class, 'not one of yours');

    $this->actingAs($account, 'portal')->post('/enumerate/support', [
        'request' => $request->reference,
        'category' => 'report_quality',
        'subject' => 'Photos don’t show the storefront clearly',
        'body' => 'The storefront photo is blurred and I can’t read the sign.',
    ])->assertRedirect();

    $ticket = EnumerateTicket::query()->sole();
    expect($ticket->reference)->toMatch('/^TKT-\d{8}-[A-Z2-9]{5}$/')
        ->and($ticket->status)->toBe(EnumerateTicket::OPEN);

    $tickets->answer($ticket, person(Role::Supervisor, 'Chidi Okeke'), 'You are right. We have asked the agent to revisit.', false);
    expect($ticket->refresh()->status)->toBe(EnumerateTicket::IN_REVIEW);

    $this->actingAs($account, 'portal')->get("/enumerate/support?ticket={$ticket->reference}")
        ->assertInertia(fn ($page) => $page->component('enumerate/Support')
            ->has('thread.messages', 2)
            ->where('thread.messages.1.author', 'Chidi · Enumerate support')
            ->where('thread.messages.0.author', 'You'));

    // Another requester cannot write into it.
    $this->actingAs(enumerateRequester('Stranger', '08035550006'), 'portal')
        ->post("/enumerate/support/{$ticket->reference}/reply", ['body' => 'Hello'])->assertNotFound();

    $tickets->answer($ticket, person(Role::Supervisor), 'New photos added and the report re-issued.', true);
    $tickets->reply($ticket->refresh(), $account, 'Thanks, but the signage is still unclear.');
    expect($ticket->refresh()->status)->toBe(EnumerateTicket::OPEN);
});

it('refunds only on an administrator’s word, never beyond what is left, and never while work is held', function () {
    $request = confirmedTier2();
    $account = $request->requester()->firstOrFail();
    $tickets = app(ManageTickets::class);
    $ticket = $tickets->open($account, $request->reference, 'report_quality', 'Wrong premises', 'The agent visited the shop next door.');
    $before = enumerateWalletNaira($account);

    expect(fn () => $tickets->refund($ticket, person(Role::Supervisor), 100_000, 'Visited the wrong shop.'))
        ->toThrow(RuntimeException::class, 'administrator');
    expect(fn () => $tickets->refund($ticket, person(Role::Admin), 600_000, 'Visited the wrong shop.'))
        ->toThrow(RuntimeException::class, 'between ₦1 and ₦5,000');

    $message = $tickets->refund($ticket, person(Role::Admin), 250_000, 'The agent visited the shop next door.');

    expect($message->refund_minor)->toBe(250_000)
        ->and(enumerateWalletNaira($account))->toBe($before + 2_500)
        ->and($ticket->refresh()->status)->toBe(EnumerateTicket::RESOLVED)
        ->and($tickets->refundable($request))->toBe(250_000)
        ->and(app(ReadLedgerBalances::class)()['balances'])->toBeTrue();

    // What was written stays written.
    // In a savepoint, so the refusal does not abort the test's transaction.
    expect(fn () => DB::transaction(fn () => DB::table('enumerate_ticket_messages')->where('id', $message->id)->update(['body' => 'changed'])))
        ->toThrow(QueryException::class);

    // Money still held for work in progress is settled by the work, not a refund.
    $running = app(DecideDeskCheck::class)(enumerateCheck($account, Tier::Location), person(Role::Supervisor), true, null);
    $held = $tickets->open($account, $running->reference, 'general', 'How long', 'How long until the visit happens?');
    expect(fn () => $tickets->refund($held, person(Role::Admin), 100_000, 'Taking too long to visit.'))
        ->toThrow(RuntimeException::class, 'still under way');
});

it('runs the desk from the console, and keeps officers out', function () {
    $request = confirmedTier2();
    $ticket = app(ManageTickets::class)->open($request->requester()->firstOrFail(), $request->reference, 'payments', 'Receipt', 'Please send a receipt for this check.');

    $this->actingAs(person(Role::Officer))->get('/console/support')->assertRedirect();

    $this->actingAs(person(Role::Supervisor))->get('/console/support')
        ->assertInertia(fn ($page) => $page->component('console/Support')
            ->where('thread.reference', $ticket->reference)
            ->where('canRefund', false));

    $this->post("/console/support/{$ticket->reference}/answer", ['body' => 'Your receipt is on the wallet page.', 'resolve' => true])
        ->assertRedirect();

    expect($ticket->refresh()->status)->toBe(EnumerateTicket::RESOLVED)
        ->and(EnumerateTicketMessage::query()->count())->toBe(2);

    $this->actingAs(person(Role::Admin))->get("/console/support?ticket={$ticket->reference}")
        ->assertInertia(fn ($page) => $page->where('canRefund', true)->where('thread.refundableMinor', 500_000));
});
