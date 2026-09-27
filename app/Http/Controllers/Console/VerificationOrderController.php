<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Field\Models\Assignment;
use App\Domain\Verification\Actions\AssignVerificationVisit;
use App\Domain\Verification\Actions\CompleteOrder;
use App\Domain\Verification\Actions\RefundOrder;
use App\Domain\Verification\Actions\SyncOrderToVisit;
use App\Domain\Verification\Enums\OrderOutcome;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Models\VerificationOrder;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Paid work, and who is going to do it.
 *
 * A supervisor's day already runs on the assignments screen, and a paid visit
 * becomes an ordinary assignment the moment it is given out. This screen exists
 * for the step before that, which the assignments screen cannot show: somebody
 * has paid, we have promised a date, and nobody has been sent yet.
 *
 * Ordered by the promise rather than by the payment. The order in most danger
 * of costing us a refund is the one that goes to the top, whoever paid first.
 */
final class VerificationOrderController
{
    public function __construct(private readonly SyncOrderToVisit $visits) {}

    public function index(Request $request): Response
    {
        $orders = VerificationOrder::query()
            ->with(['enterprise:id,trading_name', 'structure:id,ward_id', 'structure.ward:id,name', 'party:id,code,display_name', 'investorOrganisation:id,name'])
            ->whereIn('status', [
                OrderStatus::Paid->value,
                OrderStatus::Assigned->value,
                OrderStatus::InProgress->value,
                OrderStatus::Submitted->value,
            ])
            ->orderByRaw('due_by nulls last')
            ->limit(200)
            ->get();

        // Caught up with the field before it is drawn. An officer's sync moves
        // the assignment, not the order, so without this a supervisor would be
        // looking at yesterday's picture of their own team.
        $orders = $orders->map(fn (VerificationOrder $o): VerificationOrder => ($this->visits)($o));

        $today = now(config('app.timezone'))->startOfDay();

        return Inertia::render('console/Orders', [
            'orders' => $orders->map(static fn (VerificationOrder $o): array => [
                'id' => $o->id,
                'reference' => $o->reference,
                'business' => $o->enterprise->trading_name,
                // Whoever paid: the business, or an investor that commissioned
                // the visit. Exactly one is set, by check constraint.
                'party' => $o->party !== null
                    ? ($o->party->display_name ?? $o->party->code)
                    : 'Investor: '.($o->investorOrganisation->name ?? 'unknown'),
                'ward' => $o->structure?->ward?->name,
                'tier' => $o->tier,
                'urgency' => $o->urgency->value,
                'zone' => $o->zone->value,
                'feeNaira' => (int) round($o->amount_minor / 100),
                'status' => $o->status->value,
                'statusLabel' => $o->status->label(),
                'paidAt' => $o->paid_at?->toDateString(),
                'dueBy' => $o->due_by?->toDateString(),
                // Negative once we are late. The number a supervisor is
                // actually deciding on: everything else on the row is context.
                'daysLeft' => $o->due_by === null
                    ? null
                    : (int) $today->diffInDays($o->due_by->copy()->startOfDay(), false),
                'assignmentId' => $o->assignment_id,
                'awaitingAcceptance' => $o->status === OrderStatus::Submitted,
                'assignable' => $o->status === OrderStatus::Paid,
            ])->all(),
            'officers' => $this->officers(),
            'outcomes' => array_map(
                static fn (OrderOutcome $o): array => [
                    'value' => $o->value,
                    'label' => $o->label(),
                    'establishesTier' => $o->establishesTier(),
                ],
                OrderOutcome::cases(),
            ),
            'held' => $this->heldTotal(),
        ]);
    }

    public function assign(
        Request $request,
        VerificationOrder $order,
        AssignVerificationVisit $visits,
    ): RedirectResponse {
        $data = $request->validate([
            'officer_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $officer = User::query()->findOrFail($data['officer_id']);

        try {
            $assignment = $visits($order, $officer, $request->user());
        } catch (Throwable $e) {
            return back()->withErrors(['officer_id' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            '%s given to %s, due %s.',
            $order->reference,
            $officer->name,
            $assignment->due_on?->toFormattedDateString() ?? 'no date',
        ));
    }

    /** Supervisor acceptance. This is the moment the fee becomes ours. */
    public function complete(
        Request $request,
        VerificationOrder $order,
        CompleteOrder $completions,
    ): RedirectResponse {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(array_column(OrderOutcome::cases(), 'value'))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $completions(
                $order,
                $request->user(),
                OrderOutcome::from($data['outcome']),
                $data['note'] ?? null,
            );
        } catch (Throwable $e) {
            return back()->withErrors(['outcome' => $e->getMessage()]);
        }

        return back()->with('status', "{$order->reference} completed and the fee recognised.");
    }

    /**
     * A refund decided by a person rather than by the morning sweep.
     *
     * The sweep handles the promise we broke. This handles everything else: a
     * customer who asked, a duplicate, a building that turned out to be
     * unreachable. The reason is required and is shown to the customer.
     */
    public function refund(
        Request $request,
        VerificationOrder $order,
        RefundOrder $refunds,
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $refunds($order, $data['reason'], $request->user());
        } catch (Throwable $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', "{$order->reference} refunded in full.");
    }

    /**
     * Officers, with how much paid work each is already carrying.
     *
     * A supervisor handing out a visit needs to know who is already holding
     * three of them due on Thursday, and the sweep count on the assignments
     * screen does not answer that.
     *
     * @return list<array<string, mixed>>
     */
    private function officers(): array
    {
        /** @var list<object{id: int, name: string, staff_ref: string|null, visits: int}> $rows */
        $rows = DB::select(
            'select u.id, u.name, u.staff_ref,
                    count(a.id) filter (where a.closed_at is null and a.kind = ?) as visits
               from users u
               left join assignments a on a.user_id = u.id
              where u.role = ? and u.status = ?
              group by u.id, u.name, u.staff_ref
              order by u.name',
            [Assignment::KIND_VISIT, Role::Officer->value, User::STATUS_ACTIVE],
        );

        return array_map(static fn (object $r): array => [
            'id' => (int) $r->id,
            'name' => $r->name,
            'staffRef' => $r->staff_ref,
            'visits' => (int) $r->visits,
        ], $rows);
    }

    /**
     * What we are holding for work not yet done, read from the ledger.
     *
     * From the ledger rather than by summing order amounts, because the ledger
     * is the record and a second way of arriving at this number is a second
     * number. If the two ever disagree, the ledger is right.
     */
    private function heldTotal(): int
    {
        $minor = (int) DB::scalar(
            'select coalesce(-sum(e.amount_minor), 0)
               from ledger_entries e
               join ledger_accounts a on a.id = e.ledger_account_id
              where a.code = ?',
            ['liability.customer_funds_held'],
        );

        return (int) round($minor / 100);
    }
}
