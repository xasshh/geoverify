<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\PurchaseOrder;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Hands the buyer to the payment provider, and nothing more.
 *
 * The sibling of InitialisePayment for a product order, and bound by the same
 * rule: this returns a URL and marks nothing paid. The order reference is the
 * provider's reference, so the webhook finds the order by the string the buyer
 * can read off their own screen. The provider is told to offer only the channel
 * the buyer picked, which is what "Pay with" on the checkout means.
 */
final class InitialisePurchasePayment
{
    public function __invoke(PurchaseOrder $order, string $email, string $callbackUrl): string
    {
        if ($order->status !== PurchaseStatus::AwaitingPayment) {
            throw new RuntimeException('That order is not waiting to be paid.');
        }

        $secret = config('services.paystack.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('PAYSTACK_SECRET_KEY is not set, so no payment can be started.');
        }

        $response = Http::withToken($secret)
            ->acceptJson()
            ->timeout(20)
            ->post('https://api.paystack.co/transaction/initialize', [
                'email' => $email,
                // Kobo, exactly as stamped on the order.
                'amount' => $order->amount_minor,
                'currency' => $order->currency,
                'reference' => $order->reference,
                'callback_url' => $callbackUrl,
                'channels' => [$order->channel->value],
                'metadata' => [
                    'kind' => 'purchase',
                    'purchase_order_id' => $order->id,
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
