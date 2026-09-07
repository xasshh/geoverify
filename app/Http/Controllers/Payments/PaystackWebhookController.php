<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Domain\Verification\Actions\HandlePaymentWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where the money is actually confirmed.
 *
 * Thin on purpose. It reads the raw body, hands it and the signature to the
 * action, and answers. Everything that decides anything lives in the action,
 * where it can be tested without a request.
 *
 * Answers 200 to anything correctly signed, including events we do nothing
 * with. A provider that receives a 4xx will keep redelivering, and being
 * retried forever for an event we deliberately ignore is a self inflicted
 * outage.
 */
final class PaystackWebhookController
{
    public function __construct(private readonly HandlePaymentWebhook $webhooks) {}

    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $signature = (string) $request->header('x-paystack-signature', '');

        /** @var array<string, mixed> $payload */
        $payload = json_decode($raw, true) ?: [];

        try {
            $order = ($this->webhooks)($payload, $raw, $signature);
        } catch (Throwable $e) {
            // A bad signature is a 401 and nothing else is said. Anything else
            // going wrong is ours, and the provider should retry rather than
            // conclude the payment was delivered.
            if (str_contains($e->getMessage(), 'not signed')) {
                return response()->json(['status' => 'rejected'], 401);
            }

            Log::error('A signed payment webhook could not be processed.', [
                'message' => $e->getMessage(),
                'event' => $payload['event'] ?? null,
            ]);

            return response()->json(['status' => 'retry'], 500);
        }

        return response()->json([
            'status' => 'received',
            // Nothing about the order beyond whether we recognised it. The
            // provider does not need our internal state and the response body
            // is the wrong place to leak it.
            'matched' => $order !== null,
        ]);
    }
}
