<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Concerns;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationCategories;
use App\Domain\Notifications\Preferences;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A notification that goes where its recipient asked (ФО §6.13). The class names its category and builds the
 * in-app payload; the channels come from Preferences, and the e-mail is the same payload as a letter —
 * so an e-mail never says more than the bell does.
 *
 * The payload may name its subject ("subject" => [type, id]): the stored notification is then found again when
 * the recipient loses access to the subject (Retraction).
 */
trait RoutesByPreference
{
    abstract public function category(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function toDatabase(object $notifiable): array;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }
        $preferences = app(Preferences::class);
        $channels = [];
        if ($preferences->enabled($notifiable, $this->category(), NotificationCategories::IN_APP)) {
            $channels[] = 'database';
        }
        if ($notifiable->routeNotificationForMail() !== null && $preferences->enabled($notifiable, $this->category(), NotificationCategories::EMAIL)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payload = $this->toDatabase($notifiable);
        $mail = (new MailMessage)->subject((string) $payload['title']);
        foreach ((array) ($payload['lines'] ?? [$payload['body'] ?? null]) as $line) {
            if (filled($line)) {
                $mail->line((string) $line);
            }
        }
        $action = $payload['actions'][0] ?? null;
        if (is_array($action) && filled($action['url'] ?? null)) {
            $mail->action((string) ($action['label'] ?? __('notifications.open')), url((string) $action['url']));
        }

        return $mail;
    }
}
