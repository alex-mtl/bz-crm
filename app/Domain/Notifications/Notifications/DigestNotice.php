<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Notifications;

use App\Domain\Notifications\Concerns\RoutesByPreference;
use App\Domain\Notifications\Models\NotificationDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The digest of a period: section titles and their lines, as built for this reader.
 */
final class DigestNotice extends Notification implements ShouldQueue
{
    use Queueable;
    use RoutesByPreference;

    public function __construct(public readonly NotificationDigest $digest) {}

    public function category(): string
    {
        return 'digest_'.$this->digest->frequency;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $lines = [];
        foreach ($this->digest->summary as $section) {
            $lines[] = $section['title'].': '.implode('; ', (array) $section['lines']);
        }

        return [
            'format' => 'filament',
            'title' => __('notifications.digest.title_'.$this->digest->frequency, [
                'from' => $this->digest->period_start->isoFormat('LL'), 'to' => $this->digest->period_end->isoFormat('LL'),
            ]),
            'body' => implode("\n", $lines),
            'lines' => $lines,
            'icon' => 'heroicon-o-newspaper',
            'iconColor' => 'info',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'open', 'label' => __('notifications.open'), 'url' => '/admin/notification-center', 'shouldMarkAsRead' => true,
            ]],
            'kind' => 'digest',
            'subject' => ['digest', $this->digest->id],
        ];
    }
}
