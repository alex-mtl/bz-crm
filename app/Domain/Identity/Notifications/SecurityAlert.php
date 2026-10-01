<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * T3: sign-in from a new device, or a series of failed attempts.
 */
final class SecurityAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public const string NEW_DEVICE = 'new_device';

    public const string FAILED_SERIES = 'failed_series';

    public function __construct(public readonly string $kind, public readonly ?string $ip, public readonly ?string $userAgent) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('identity.mail.security_'.$this->kind.'_subject'))
            ->line(__('identity.mail.security_'.$this->kind.'_line'))
            ->line(__('identity.mail.security_details', ['ip' => $this->ip ?? '—', 'agent' => $this->userAgent ?? '—']))
            ->line(__('identity.mail.security_advice'));
    }
}
