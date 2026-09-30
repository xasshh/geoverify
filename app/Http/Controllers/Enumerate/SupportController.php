<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enumerate;

use App\Domain\Enumerate\Actions\EnumerateContext;
use App\Domain\Enumerate\Actions\ManageTickets;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\EnumerateTicket;
use App\Domain\Enumerate\Models\EnumerateTicketMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Support and complaints, from the requester's side, to board 32. The desk is
 * shown as "Enumerate support" with the first name of whoever answered, never
 * their contact details.
 */
final class SupportController
{
    public function __construct(
        private readonly EnumerateController $enumerate,
        private readonly ManageTickets $tickets,
    ) {}

    public function index(Request $request, EnumerateContext $context): Response
    {
        $account = EnumerateController::account($request);
        $tickets = EnumerateTicket::query()
            ->where('portal_account_id', $account->id)
            ->with('request')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        $reference = $request->query('ticket');
        $open = is_string($reference) ? $tickets->firstWhere('reference', $reference) : null;

        return Inertia::render('enumerate/Support', [
            'frame' => $this->enumerate->frame($request, $account),
            'tickets' => $tickets->map(static fn (EnumerateTicket $t): array => self::row($t))->values()->all(),
            'thread' => $open === null ? null : self::thread($open, false),
            // For the new-complaint form: the requester's own verifications.
            'requests' => EnumerateRequest::query()
                ->where('wallet_id', $context->wallet($request, $account)->id)
                ->orderByDesc('paid_at')
                ->limit(50)
                ->get(['reference', 'subject_name', 'tier'])
                ->map(static fn (EnumerateRequest $r): array => ['reference' => $r->reference, 'business' => $r->subject_name, 'tier' => $r->tier->value])
                ->values()->all(),
            'start' => is_string($request->query('request')) ? $request->query('request') : null,
            'categories' => EnumerateTicket::CATEGORIES,
        ]);
    }

    public function store(Request $request, EnumerateContext $context): RedirectResponse
    {
        $account = EnumerateController::account($request);
        $input = $request->validate([
            'request' => ['nullable', 'string', 'max:32'],
            'category' => ['required', 'string', 'max:24'],
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $ticket = $this->tickets->open($account, $input['request'] ?? null, $input['category'], $input['subject'], $input['body'], $context->wallet($request, $account));
        } catch (RuntimeException $e) {
            return back()->withErrors(['body' => $e->getMessage()]);
        }

        return redirect()->route('enumerate.support', ['ticket' => $ticket->reference])
            ->with('status', 'Thank you. We reply within one working day.');
    }

    public function reply(Request $request, string $reference): RedirectResponse
    {
        $account = EnumerateController::account($request);
        $input = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $ticket = EnumerateTicket::query()->where('reference', $reference)->where('portal_account_id', $account->id)->firstOrFail();

        try {
            $this->tickets->reply($ticket, $account, $input['body']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['body' => $e->getMessage()]);
        }

        return redirect()->route('enumerate.support', ['ticket' => $ticket->reference]);
    }

    /** @return array<string, mixed> */
    public static function row(EnumerateTicket $ticket): array
    {
        return [
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'category' => EnumerateTicket::CATEGORIES[$ticket->category] ?? $ticket->category,
            'requestRef' => $ticket->request?->reference,
            'business' => $ticket->request?->subject_name,
            'tier' => $ticket->request?->tier->value,
            'openedAt' => $ticket->created_at?->toIso8601String(),
            'updatedAt' => $ticket->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A thread. The requester sees the desk as a first name and "Enumerate
     * support"; the desk sees who wrote each message.
     *
     * @return array<string, mixed>
     */
    public static function thread(EnumerateTicket $ticket, bool $forStaff): array
    {
        return self::row($ticket) + [
            'messages' => $ticket->messages()->with(['staff', 'account'])->get()->map(static fn (EnumerateTicketMessage $m): array => [
                'id' => $m->id,
                'mine' => $m->portal_account_id !== null,
                'author' => $m->portal_account_id !== null
                    ? ($forStaff ? ($m->account->name ?? 'Requester') : 'You')
                    : ($forStaff ? ($m->staff->name ?? 'Support') : explode(' ', (string) ($m->staff->name ?? 'Support'))[0].' · Enumerate support'),
                'body' => $m->body,
                'refundMinor' => $m->refund_minor,
                'at' => $m->created_at->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
