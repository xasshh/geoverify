<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Enumerate\Actions\ManageTickets;
use App\Domain\Enumerate\Models\EnumerateTicket;
use App\Http\Controllers\Enumerate\SupportController as RequesterSupport;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The Enumerate support desk, in the console. Oldest waiting first: an open
 * thread is somebody who wrote and has not heard back. Refunds are offered
 * to administrators only; a supervisor answers and can schedule a revisit.
 */
final class SupportController
{
    public function __construct(private readonly ManageTickets $tickets) {}

    public function index(Request $request): Response
    {
        $user = self::staff($request);
        $tickets = EnumerateTicket::query()
            ->with('request')
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'in_review' THEN 1 ELSE 2 END")
            ->orderBy('updated_at')
            ->limit(200)
            ->get();

        $reference = $request->query('ticket');
        $open = is_string($reference) ? EnumerateTicket::query()->where('reference', $reference)->first() : $tickets->first();

        return Inertia::render('console/Support', [
            'tickets' => $tickets->map(static fn (EnumerateTicket $t): array => RequesterSupport::row($t))->values()->all(),
            'thread' => $open === null ? null : RequesterSupport::thread($open, true) + [
                'refundableMinor' => $open->request !== null && $open->request->status->finished() ? $this->tickets->refundable($open->request) : null,
            ],
            'canRefund' => $user->administers(),
        ]);
    }

    public function answer(Request $request, string $reference): RedirectResponse
    {
        $input = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'resolve' => ['required', 'boolean'],
        ]);

        $ticket = EnumerateTicket::query()->where('reference', $reference)->firstOrFail();

        try {
            $this->tickets->answer($ticket, self::staff($request), $input['body'], (bool) $input['resolve']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['body' => $e->getMessage()]);
        }

        return redirect()->route('console.support', ['ticket' => $ticket->reference])->with('status', 'Sent.');
    }

    public function refund(Request $request, string $reference): RedirectResponse
    {
        $input = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $ticket = EnumerateTicket::query()->where('reference', $reference)->firstOrFail();

        try {
            $this->tickets->refund($ticket, self::staff($request), (int) round(((float) $input['amount']) * 100), $input['reason']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('console.support', ['ticket' => $ticket->reference])->with('status', 'Refunded to the wallet.');
    }

    private static function staff(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->supervises(), 403);

        return $user;
    }
}
