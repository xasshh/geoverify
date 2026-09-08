<?php

declare(strict_types=1);

use App\Domain\Ledger\Actions\ReconcileWithProvider;
use App\Domain\Verification\Actions\RecordPayment;
use App\Domain\Verification\Actions\RefundOrder;
use App\Domain\Verification\Models\VerificationOrder;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * M8: proving the two records of the same money still agree.
 *
 * The provider's word and ours drift for undramatic reasons: a webhook that
 * never arrived, a charge captured after our timeout, a refund somebody issued
 * by hand from a dashboard. None of them raises an error at the time, which is
 * why the question has to be asked on a schedule rather than when somebody
 * suspects something.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    config()->set('services.paystack.secret', 'sk_test_reconcile');
});

/** A paid order, and nothing else done to it. */
function paidOrder(): VerificationOrder
{
    $it = buyerWithShop();
    $order = placeOrderFor($it);

    return app(RecordPayment::class)($order, $order->reference)->refresh();
}

/**
 * The provider's transaction list, shaped the way it answers.
 *
 * @param  list<array{reference: string, amount: int}>  $charges
 */
function providerSays(array $charges): void
{
    Http::fake([
        'api.paystack.co/transaction*' => Http::response([
            'status' => true,
            'data' => array_map(static fn (array $c): array => [
                'id' => random_int(1000, 9999),
                'reference' => $c['reference'],
                'amount' => $c['amount'],
                'currency' => 'NGN',
                'status' => 'success',
                'paid_at' => Carbon::now()->toIso8601String(),
            ], $charges),
        ]),
    ]);
}

/**
 * The window the command uses by default, as arguments.
 *
 * @return array{0: Carbon, 1: Carbon}
 */
function window(): array
{
    return [Carbon::now()->subDays(7)->startOfDay(), Carbon::now()->addDay()];
}

it('reports agreement when both sides say the same thing', function () {
    $order = paidOrder();

    providerSays([['reference' => $order->reference, 'amount' => $order->amount_minor]]);

    $report = app(ReconcileWithProvider::class)(...window());

    expect($report['drift_minor'])->toBe(0)
        ->and($report['provider_count'])->toBe(1)
        ->and($report['ledger_count'])->toBe(1)
        ->and($report['missing_from_ledger'])->toBe([])
        ->and($report['missing_from_provider'])->toBe([])
        ->and($report['mismatched'])->toBe([]);

    $this->artisan('geoverify:reconcile-ledger')->assertSuccessful();
});

it('names money the provider took that we never wrote down', function () {
    $order = paidOrder();

    // The order the webhook never landed for. The customer has paid and this
    // register believes they have not, which is the direction of drift that
    // costs somebody the visit they bought.
    providerSays([
        ['reference' => $order->reference, 'amount' => $order->amount_minor],
        ['reference' => 'GV-NEVER-HEARD', 'amount' => 1_500_000],
    ]);

    $report = app(ReconcileWithProvider::class)(...window());

    expect($report['drift_minor'])->toBe(1_500_000)
        ->and($report['missing_from_ledger'])->toHaveCount(1)
        ->and($report['missing_from_ledger'][0]['reference'])->toBe('GV-NEVER-HEARD');

    // And it exits loudly, because a scheduler that is told nothing reports
    // nothing.
    $this->artisan('geoverify:reconcile-ledger')
        ->expectsOutputToContain('Collected but not recorded')
        ->assertFailed();
});

it('names money we wrote down that the provider does not know about', function () {
    $order = paidOrder();

    providerSays([]);

    $report = app(ReconcileWithProvider::class)(...window());

    expect($report['drift_minor'])->toBe(-$order->amount_minor)
        ->and($report['missing_from_provider'])->toHaveCount(1)
        ->and($report['missing_from_provider'][0]['reference'])->toBe($order->reference);

    $this->artisan('geoverify:reconcile-ledger')
        ->expectsOutputToContain('Recorded but not collected')
        ->assertFailed();
});

it('names a reference the two sides price differently', function () {
    $order = paidOrder();

    // A partial capture, or a fee taken at a tariff nobody told us about. The
    // reference matches, so neither list is missing anything: only the amount
    // is wrong, which is the one that hides best.
    providerSays([['reference' => $order->reference, 'amount' => $order->amount_minor - 50_000]]);

    $report = app(ReconcileWithProvider::class)(...window());

    expect($report['mismatched'])->toHaveCount(1)
        ->and($report['mismatched'][0]['provider_minor'])->toBe($order->amount_minor - 50_000)
        ->and($report['mismatched'][0]['ledger_minor'])->toBe($order->amount_minor)
        ->and($report['drift_minor'])->toBe(-50_000);
});

it('does not call a refund drift', function () {
    $order = paidOrder();

    app(RefundOrder::class)($order, 'We did not attend in time.');

    // The provider still lists the charge it took: a refund is a separate
    // record on its side. Matching those two against each other would compare
    // different things and call the difference drift, so the refund is counted
    // and shown rather than paired.
    providerSays([['reference' => $order->reference, 'amount' => $order->amount_minor]]);

    $report = app(ReconcileWithProvider::class)(...window());

    expect($report['drift_minor'])->toBe(0)
        ->and($report['refunds_minor'])->toBe(-$order->amount_minor);
});

it('reads every page the provider offers', function () {
    $full = array_map(static fn (int $n): array => [
        'id' => $n,
        'reference' => "GV-PAGE-{$n}",
        'amount' => 100,
        'paid_at' => Carbon::now()->toIso8601String(),
    ], range(1, 100));

    Http::fakeSequence()
        ->push(['status' => true, 'data' => $full])
        ->push(['status' => true, 'data' => [[
            'id' => 101,
            'reference' => 'GV-PAGE-101',
            'amount' => 100,
            'paid_at' => Carbon::now()->toIso8601String(),
        ]]]);

    $report = app(ReconcileWithProvider::class)(...window());

    // A full page means there may be another. Stopping at the first would
    // report a clean day by simply not looking at the rest of it.
    expect($report['provider_count'])->toBe(101);
});

it('refuses to reconcile against a provider we have no key for', function () {
    config()->set('services.paystack.secret', null);

    expect(fn () => app(ReconcileWithProvider::class)(...window()))
        ->toThrow(RuntimeException::class);

    $this->artisan('geoverify:reconcile-ledger')->assertFailed();
});

it('refuses a window that ends before it starts', function () {
    $this->artisan('geoverify:reconcile-ledger', [
        '--from' => '2026-09-07',
        '--to' => '2026-09-01',
    ])->assertExitCode(2);
});
