<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\EnumerateTicket;
use App\Domain\Enumerate\Models\EnumerateTicketMessage;
use App\Domain\Enumerate\Models\EnumerateWallet;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Support and complaints, to board 32.
 *
 * A thread between one requester and the support desk. The desk answers from
 * the console and can schedule another visit by saying so; a refund is an
 * admin's ruling, as a purchase dispute's is (decision 8), posted to the
 * ledger here and written into the thread so the requester sees the money
 * and the reason together.
 */
final class ManageTickets
{
    public function __construct(
        private readonly MintReference $mint,
        private readonly ManageRequesterWallet $wallets,
        private readonly PostTransaction $post,
    ) {}

    public function open(PortalAccount $account, ?string $requestReference, string $category, string $subject, string $body, ?EnumerateWallet $wallet = null): EnumerateTicket
    {
        if (! array_key_exists($category, EnumerateTicket::CATEGORIES)) {
            throw new RuntimeException('Choose what the complaint is about.');
        }

        $subject = trim($subject);
        $body = trim($body);

        if (mb_strlen($subject) < 5 || mb_strlen($body) < 10) {
            throw new RuntimeException('Give the complaint a title and say what went wrong, in a sentence or two.');
        }

        $request = null;

        if ($requestReference !== null && $requestReference !== '') {
            $request = EnumerateRequest::query()
                ->where('reference', $requestReference)
                ->where('wallet_id', ($wallet ?? $this->wallets->walletFor($account))->id)
                ->first() ?? throw new RuntimeException('That verification is not one of yours.');
        }

        return DB::transaction(function () use ($account, $request, $category, $subject, $body): EnumerateTicket {
            $ticket = $this->create([
                'portal_account_id' => $account->id,
                'enumerate_request_id' => $request?->id,
                'category' => $category,
                'subject' => mb_substr($subject, 0, 160),
                'status' => EnumerateTicket::OPEN,
            ]);

            $this->write($ticket, $body, account: $account);

            if ($request !== null) {
                VerificationEvent::recordForBuyer($request, 'enumerate.complaint_opened', $account, [
                    'ticket' => $ticket->reference,
                    'category' => $category,
                ]);
            }

            return $ticket;
        });
    }

    /** The requester writes again. A resolved thread opens again with it. */
    public function reply(EnumerateTicket $ticket, PortalAccount $account, string $body): EnumerateTicketMessage
    {
        if ($ticket->portal_account_id !== $account->id) {
            throw new RuntimeException('That complaint is not yours.');
        }

        return DB::transaction(function () use ($ticket, $account, $body): EnumerateTicketMessage {
            $message = $this->write($ticket, $body, account: $account);
            $ticket->update(['status' => EnumerateTicket::OPEN, 'resolved_at' => null]);

            return $message;
        });
    }

    /** The desk answers, and says where the thread now stands. */
    public function answer(EnumerateTicket $ticket, User $staff, string $body, bool $resolve): EnumerateTicketMessage
    {
        if (! $staff->supervises()) {
            throw new RuntimeException('Only the support desk answers complaints.');
        }

        return DB::transaction(function () use ($ticket, $staff, $body, $resolve): EnumerateTicketMessage {
            $message = $this->write($ticket, $body, staff: $staff);
            $ticket->update([
                'status' => $resolve ? EnumerateTicket::RESOLVED : EnumerateTicket::IN_REVIEW,
                'resolved_at' => $resolve ? now() : null,
            ]);

            return $message;
        });
    }

    /**
     * An admin refunds part or all of a finished request to the wallet it was
     * paid from. Never more than is still unreturned, and never while the
     * money is still held for work in progress: that is settled by the work.
     */
    public function refund(EnumerateTicket $ticket, User $admin, int $amountMinor, string $reason): EnumerateTicketMessage
    {
        if (! $admin->administers()) {
            throw new RuntimeException('A refund is an administrator’s ruling.');
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw new RuntimeException('Say why, in a sentence the requester will read.');
        }

        return DB::transaction(function () use ($ticket, $admin, $amountMinor, $reason): EnumerateTicketMessage {
            /** @var EnumerateRequest|null $request */
            $request = $ticket->enumerate_request_id === null
                ? null
                : EnumerateRequest::query()->whereKey($ticket->enumerate_request_id)->lockForUpdate()->first();

            if ($request === null) {
                throw new RuntimeException('A refund needs a verification to be about.');
            }

            if (! $request->status->finished()) {
                throw new RuntimeException('That verification is still under way; its money is still held for the work.');
            }

            $refundable = $this->refundable($request);

            if ($amountMinor <= 0 || $amountMinor > $refundable) {
                throw new RuntimeException(sprintf('Refund between ₦1 and ₦%s.', number_format($refundable / 100)));
            }

            $transaction = ($this->post)(
                [
                    LedgerAccount::REFUNDS => $amountMinor,
                    LedgerAccount::REQUESTER_WALLETS => -$amountMinor,
                ],
                LedgerEntry::REASON_REFUNDED,
                null,
                "Refund on {$ticket->reference} for {$request->reference}",
                enumerateRequestId: $request->id,
                walletId: $request->wallet_id,
            );

            $message = $this->write(
                $ticket,
                sprintf('₦%s has been returned to your wallet. %s', number_format($amountMinor / 100), trim($reason)),
                staff: $admin,
                refundMinor: $amountMinor,
            );

            $ticket->update(['status' => EnumerateTicket::RESOLVED, 'resolved_at' => now()]);

            VerificationEvent::record($request, 'enumerate.refunded', $admin, [
                'ticket' => $ticket->reference,
                'amount_minor' => $amountMinor,
                'transaction_uuid' => $transaction,
            ]);

            return $message;
        });
    }

    /** What was paid for a request and has not yet gone back to its wallet. */
    public function refundable(EnumerateRequest $request): int
    {
        $returned = -(int) DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('ledger_accounts.code', LedgerAccount::REQUESTER_WALLETS)
            ->where('ledger_entries.enumerate_request_id', $request->id)
            ->where('ledger_entries.amount_minor', '<', 0)
            ->sum('ledger_entries.amount_minor');

        return max(0, $request->price_minor - $returned);
    }

    private function write(EnumerateTicket $ticket, string $body, ?PortalAccount $account = null, ?User $staff = null, ?int $refundMinor = null): EnumerateTicketMessage
    {
        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('Write something first.');
        }

        $message = EnumerateTicketMessage::query()->create([
            'enumerate_ticket_id' => $ticket->id,
            'portal_account_id' => $account?->id,
            'user_id' => $staff?->id,
            'body' => mb_substr($body, 0, 4000),
            'refund_minor' => $refundMinor,
            'created_at' => now(),
        ]);

        $ticket->touch();

        return $message;
    }

    /** @param  array<string, mixed>  $attributes */
    private function create(array $attributes): EnumerateTicket
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(fn (): EnumerateTicket => EnumerateTicket::query()->create(
                    ['reference' => ($this->mint)('TKT', 5)] + $attributes,
                ));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 4) {
                    throw $e;
                }
            }
        }
    }
}
