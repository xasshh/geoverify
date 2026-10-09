<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An email that asks the reader to do one thing: verify an address, set a
 * password, accept an invitation. One template for all of them, so every
 * account email reads the same and carries one button.
 *
 * Sent synchronously, like the codes it replaced: a refusal from the mail
 * service reaches the person waiting on the screen instead of a queue.
 */
final class PortalActionMail extends Mailable
{
    use Queueable;

    /** @param  list<string>  $lines */
    public function __construct(
        public readonly string $heading,
        public readonly array $lines,
        public readonly string $buttonLabel,
        public readonly string $url,
        public readonly string $footnote,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.portal-action');
    }
}
