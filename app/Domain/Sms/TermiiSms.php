<?php

declare(strict_types=1);

namespace App\Domain\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Termii, a Nigerian gateway built for one-time codes.
 *
 * Sent on the `dnd` channel, the transactional route that still reaches a
 * number registered on the NCC's do-not-disturb list. The `generic` route is
 * filtered on those numbers, which is a large share of Nigerian lines, and a
 * sign-in code that silently never arrives is the worst way this can fail.
 * The dnd route needs a sender ID approved in the Termii dashboard.
 *
 * Endpoint as documented at developers.termii.com: POST {base}/api/sms/send
 * with the API key in the body. Each account is shown its own base URL.
 *
 * Neither the message nor Termii's reply to it is logged: the message is a
 * live code. What is logged on failure is the last four digits, the status and
 * Termii's own reason (an empty balance, an unapproved sender ID), which is
 * what somebody fixing it needs and holds nothing of the message.
 */
final class TermiiSms implements SmsGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $senderId,
        private readonly string $channel = 'dnd',
    ) {
        if ($apiKey === '' || $senderId === '') {
            Log::critical('TERMII_API_KEY and TERMII_SENDER_ID must both be set to use the Termii gateway.');

            throw SmsUndelivered::make();
        }
    }

    public function send(string $phone, string $message): void
    {
        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->acceptJson()
                ->timeout(10)
                ->post('/api/sms/send', [
                    'api_key' => $this->apiKey,
                    // Termii takes the international number without the plus.
                    'to' => ltrim($phone, '+'),
                    'from' => $this->senderId,
                    'sms' => $message,
                    'type' => 'plain',
                    'channel' => $this->channel,
                ]);
        } catch (ConnectionException) {
            $this->fail($phone, 'unreachable', null);
        }

        if (! $response->successful() || ! filled($response->json('message_id'))) {
            $reason = $response->json('message');

            $this->fail($phone, (string) $response->status(), is_string($reason) ? mb_substr($reason, 0, 160) : null);
        }
    }

    private function fail(string $phone, string $status, ?string $reason): never
    {
        Log::error('Termii did not accept a text message.', [
            'phone' => '...'.substr($phone, -4),
            'status' => $status,
            'reason' => $reason,
        ]);

        throw SmsUndelivered::make();
    }
}
