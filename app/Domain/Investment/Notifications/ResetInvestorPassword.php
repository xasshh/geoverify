<?php

declare(strict_types=1);

namespace App\Domain\Investment\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The reset link for an investor, pointing at the investor portal's own form. */
final class ResetInvestorPassword extends Notification
{
    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = (string) ($notifiable->email ?? '');

        return (new MailMessage)
            ->subject('Reset your GeoVerify investor password')
            ->line('Somebody asked to reset the password for this investor account.')
            ->action('Choose a new password', route('invest.reset-password', ['token' => $this->token, 'email' => $email]))
            ->line('The link works for 60 minutes. If you did not ask for this, you can ignore it.');
    }
}
