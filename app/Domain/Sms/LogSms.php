<?php

declare(strict_types=1);

namespace App\Domain\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Writes the message to the log instead of sending it.
 *
 * The only way to sign in on a developer machine, and the line the browser
 * specs read the code back from. Anywhere else it refuses to exist, because a
 * log file holding live one-time codes is a log file holding a way into
 * somebody's business, readable by whoever operates the server.
 */
final class LogSms implements SmsGateway
{
    public function __construct(bool $permitted)
    {
        // The person asking sees only that the code could not be sent; what
        // is wrong with the server goes to the log, for whoever runs it.
        if (! $permitted) {
            Log::critical('The log SMS driver only runs locally. Set SMS_DRIVER=termii and its keys.');

            throw SmsUndelivered::make();
        }
    }

    public function send(string $phone, string $message): void
    {
        Log::info("SMS to {$phone}: {$message}");
    }
}
