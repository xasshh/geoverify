<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Verification\Models\PaymentWebhookEvent;
use App\Domain\Verification\Models\VerificationOrder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The one thing this application believes about money.
 *
 * A customer's browser comes back from the payment page with a message, and
 * that message is worth nothing: it can be edited, replayed, or typed into the
 * address bar by somebody who never paid. The provider's signed webhook is the
 * only channel that carries authority here, and the callback route exists
 * purely to tell the customer what their order looks like now.
 *
 * Three defences, in order. The signature is checked before anything is read
 * out of the body. The delivery is written down before it is acted on, so a
 * replayed event collides with a unique index rather than paying an order
 * twice. And RecordPayment refuses a second payment on its own account, so even
 * a provider that changes its event ids cannot double post.
 *
 * Unrecognised events are stored and ignored rather than rejected. A provider
 * adding a new event type must not start collecting 400s from us, and the row
 * is worth having when somebody asks in six months what we were sent.
 */
final class HandlePaymentWebhook
{
    public function __construct(private readonly RecordPayment $payments) {}

    /**
     * @param  array<string, mixed>  $payload  The decoded body, believed only once the signature holds.
     */
    public function __invoke(array $payload, string $rawBody, string $signature): ?VerificationOrder
    {
        if (! $this->signatureIsValid($rawBody, $signature)) {
            // Recorded as an unverified delivery and refused. Somebody probing
            // this endpoint is worth knowing about, and a silent 200 would hide
            // it from us as well as from them.
            $this->record($payload, $signature, verified: false, note: 'Signature did not match.');

            throw new RuntimeException('That webhook was not signed by the provider.');
        }

        $event = $this->record($payload, $signature, verified: true);

        if ($event === null) {
            // Seen before. The provider is redelivering because it did not get
            // our 200, or is simply sending it twice. Either way the work is
            // already done and doing it again is exactly what must not happen.
            return null;
        }

        $type = (string) ($payload['event'] ?? '');

        if ($type !== 'charge.success') {
            $event->update([
                'processed_at' => now(),
                'processing_note' => "Nothing to do for {$type}.",
            ]);

            return null;
        }

        $reference = $this->reference($payload);
        $order = VerificationOrder::query()->where('reference', $reference)->first();

        if (! $order instanceof VerificationOrder) {
            // Money arrived against something we cannot find. Never dropped:
            // this is a person who has paid, and somebody has to look at it.
            Log::error('A payment arrived for an order we do not have.', [
                'reference' => $reference,
                'provider_event_id' => $event->provider_event_id,
            ]);

            $event->update([
                'processed_at' => now(),
                'processing_note' => "No order called {$reference}.",
            ]);

            return null;
        }

        $paid = ($this->payments)($order, $reference, $this->paidAt($payload));

        $event->update([
            'processed_at' => now(),
            'payment_reference' => $reference,
            'processing_note' => "Applied to {$order->reference}.",
        ]);

        return $paid;
    }

    /**
     * Paystack signs the raw body with the secret key, as sha512 HMAC.
     *
     * Against the raw body rather than a re-encoding of the decoded array: any
     * difference in key order, unicode escaping or float formatting would
     * produce a different digest, and we would reject every genuine delivery.
     */
    public function signatureIsValid(string $rawBody, string $signature): bool
    {
        $secret = config('services.paystack.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException(
                'PAYSTACK_SECRET_KEY is not set, so no webhook can be verified. Refusing to accept payment notifications on trust.',
            );
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);
    }

    /**
     * Writes the delivery down, or reports that we have it already.
     *
     * The unique index on (provider, provider_event_id) is what decides, not a
     * lookup beforehand: two deliveries arriving together would both find
     * nothing and both proceed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function record(
        array $payload,
        string $signature,
        bool $verified,
        ?string $note = null,
    ): ?PaymentWebhookEvent {
        try {
            // Inside its own transaction, which is a savepoint when there is
            // already one open. A unique violation in Postgres aborts the
            // enclosing transaction, so catching it without a savepoint would
            // leave every later statement in the request failing with
            // "transaction is aborted" instead of proceeding.
            return DB::transaction(fn (): PaymentWebhookEvent => PaymentWebhookEvent::query()->create([
                'provider' => 'paystack',
                'provider_event_id' => $this->eventId($payload, $signature),
                'event_type' => (string) ($payload['event'] ?? 'unknown'),
                'signature_verified' => $verified,
                'payment_reference' => $this->reference($payload),
                'payload' => $payload,
                'processing_note' => $note,
                'processed_at' => $verified ? null : now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * What makes this delivery distinct from the next one.
     *
     * Paystack does not send an event id, so the charge's own id stands in:
     * that is stable across redeliveries of the same charge, which is exactly
     * the property idempotency needs. Falling back to the signature keeps the
     * column populated for an event shaped differently from the ones we know.
     *
     * @param  array<string, mixed>  $payload
     */
    private function eventId(array $payload, string $signature): string
    {
        $data = $payload['data'] ?? [];
        $id = is_array($data) ? ($data['id'] ?? null) : null;
        $type = (string) ($payload['event'] ?? 'unknown');

        return $id === null
            ? $type.':'.substr(hash('sha256', $signature), 0, 32)
            : $type.':'.$id;
    }

    /** @param  array<string, mixed>  $payload */
    private function reference(array $payload): ?string
    {
        $data = $payload['data'] ?? [];

        if (! is_array($data)) {
            return null;
        }

        $reference = $data['reference'] ?? null;

        return is_string($reference) ? $reference : null;
    }

    /**
     * When the provider says it happened, not when we got around to reading it.
     *
     * The SLA clock hangs off this. A webhook delayed by an outage on either
     * side must not quietly shorten the time we promised ourselves.
     *
     * @param  array<string, mixed>  $payload
     */
    private function paidAt(array $payload): ?Carbon
    {
        $data = $payload['data'] ?? [];
        $at = is_array($data) ? ($data['paid_at'] ?? null) : null;

        if (! is_string($at) || $at === '') {
            return null;
        }

        return Carbon::parse($at)->setTimezone(config('app.timezone'));
    }
}
