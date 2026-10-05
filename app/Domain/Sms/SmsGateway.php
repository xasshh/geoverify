<?php

declare(strict_types=1);

namespace App\Domain\Sms;

/**
 * Puts a text message in front of a phone.
 *
 * Every text this system sends is a one-time code, so delivery is synchronous:
 * the person is on a screen waiting for it, and a gateway that failed must say
 * so there and then rather than in a queue nobody is watching.
 */
interface SmsGateway
{
    /**
     * @param  string  $phone  E.164, as NormalisePhone gives it (+234...)
     *
     * @throws SmsUndelivered when the gateway did not accept the message
     */
    public function send(string $phone, string $message): void;
}
