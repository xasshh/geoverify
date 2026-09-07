<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Models\VerificationOrder;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Hands the customer to the payment provider, and nothing more.
 *
 * Our order reference is the provider's reference. One identifier through the
 * whole journey means the webhook can find the order by the same string the
 * customer can read off their own screen, and no mapping table exists to drift.
 *
 * Nothing here marks anything paid. This call returns a URL and that is all it
 * is allowed to do: the money is confirmed by the signed webhook or it is not
 * confirmed at all.
 */
final class InitialisePayment
{
    public function __invoke(VerificationOrder $order, string $email, string $callbackUrl): string
    {
        if ($order->status !== OrderStatus::AwaitingPayment) {
            throw new RuntimeException('That order is not waiting to be paid.');
        }

        $secret = config('services.paystack.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException(
                'PAYSTACK_SECRET_KEY is not set, so no payment can be started.',
            );
        }

        $response = Http::withToken($secret)
            ->acceptJson()
            ->timeout(20)
            ->post('https://api.paystack.co/transaction/initialize', [
                'email' => $email,
                // Kobo, exactly as stamped on the order. Never recomputed here:
                // the amount the customer is charged and the amount we recorded
                // agreeing to charge must be the same integer.
                'amount' => $order->amount_minor,
                'currency' => $order->currency,
                'reference' => $order->reference,
                'callback_url' => $callbackUrl,
                // Read back off the charge when the webhook arrives, and useful
                // in the provider's own dashboard when somebody is chasing a
                // payment by hand.
                'metadata' => [
                    'order_id' => $order->id,
                    'tier' => $order->tier,
                    'enterprise_id' => $order->enterprise_id,
                ],
            ]);

        $url = $response->json('data.authorization_url');

        if (! $response->successful() || ! is_string($url)) {
            throw new RuntimeException(
                'The payment provider did not give us a checkout page: '
                .(is_string($response->json('message')) ? $response->json('message') : 'no reason given'),
            );
        }

        return $url;
    }
}
