<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ApplicationDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly bool $approved, public readonly ?string $reason = null) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__($this->approved ? 'identity.mail.approved_subject' : 'identity.mail.rejected_subject'))
            ->line(__($this->approved ? 'identity.mail.approved_line' : 'identity.mail.rejected_line'));

        if (! $this->approved && $this->reason !== null) {
            $mail->line(__('identity.mail.reason', ['reason' => $this->reason]));
        }

        return $this->approved
            ? $mail->action(__('identity.mail.open_platform'), url('/admin'))
            : $mail->action(__('identity.mail.view_application'), route('account.status'));
    }
}
