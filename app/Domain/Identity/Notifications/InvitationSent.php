<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

final class InvitationSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $url, public readonly Carbon $expiresAt, public readonly string $inviterName) {}

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
            ->subject(__('identity.mail.invitation_subject'))
            ->line(__('identity.mail.invitation_line', ['name' => $this->inviterName]))
            ->action(__('identity.mail.invitation_action'), $this->url)
            ->line(__('identity.mail.invitation_expires', ['date' => $this->expiresAt->isoFormat('LLL')]));
    }
}
