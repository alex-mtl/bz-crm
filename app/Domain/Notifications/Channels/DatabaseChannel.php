<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Channels;

use Illuminate\Notifications\Channels\DatabaseChannel as BaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Stores the category and the subject of a notification in columns of their own, so the center can filter by
 * category and a notification about an object can be found when access to the object is lost.
 */
final class DatabaseChannel extends BaseChannel
{
    /**
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        $payload = parent::buildPayload($notifiable, $notification);
        $subject = is_array($payload['data']) ? ($payload['data']['subject'] ?? null) : null;

        return [
            ...$payload,
            'category' => method_exists($notification, 'category') ? $notification->category() : null,
            'subject_type' => is_array($subject) ? ($subject[0] ?? null) : null,
            'subject_id' => is_array($subject) ? ($subject[1] ?? null) : null,
        ];
    }
}
