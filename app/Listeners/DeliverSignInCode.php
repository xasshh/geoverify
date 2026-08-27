<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Party\Events\SignInCodeIssued;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Puts a one-time code in front of the person who asked for it.
 *
 * There is no SMS gateway procured, so on a developer machine this logs the
 * code, which is the only way to sign in locally. Anywhere else it refuses
 * loudly rather than quietly writing a live credential to a log file that
 * operations staff can read.
 */
final class DeliverSignInCode
{
    public function handle(SignInCodeIssued $event): void
    {
        if (app()->isLocal()) {
            Log::info("Portal sign-in code for {$event->phone}: {$event->code}");

            return;
        }

        Log::error('No SMS gateway is configured, so a portal sign-in code could not be delivered.', [
            'phone' => $event->maskedPhone,
        ]);

        throw new RuntimeException('We cannot send a code right now. Please try again shortly.');
    }
}
