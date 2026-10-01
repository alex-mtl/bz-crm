<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use App\Domain\Identity\Models\Impersonation;
use App\Domain\Identity\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Д-19: the user is told that someone signed in to their account — who, when, why and until when.
 * By e-mail when there is an address, and always in the panel's notifications.
 */
final class ImpersonationNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Impersonation $impersonation) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->email !== null ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('identity.mail.impersonation_subject'))
            ->line($this->line())
            ->line(__('identity.mail.impersonation_reason', ['reason' => $this->impersonation->reason]))
            ->line(__('identity.mail.impersonation_advice'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'format' => 'filament',
            'title' => __('identity.mail.impersonation_subject'),
            'body' => $this->line().' '.__('identity.mail.impersonation_reason', ['reason' => $this->impersonation->reason]),
            'icon' => 'heroicon-o-eye',
            'iconColor' => 'warning',
            'duration' => 'persistent',
            'actions' => [],
            'kind' => 'impersonation',
            'impersonation_id' => $this->impersonation->id,
        ];
    }

    private function line(): string
    {
        return __('identity.mail.impersonation_line', [
            'name' => $this->impersonation->impersonator->getFilamentName(),
            'from' => $this->impersonation->started_at->format('d.m.Y H:i'),
            'until' => $this->impersonation->expires_at->format('H:i'),
        ]);
    }
}
