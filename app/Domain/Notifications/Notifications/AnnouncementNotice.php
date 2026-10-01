<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Notifications;

use App\Domain\Notifications\Concerns\RoutesByPreference;
use App\Domain\Notifications\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An announcement reaching one of its recipients. A critical one goes by every channel regardless of preferences.
 */
final class AnnouncementNotice extends Notification implements ShouldQueue
{
    use Queueable;
    use RoutesByPreference;

    public function __construct(public readonly Announcement $announcement) {}

    public function category(): string
    {
        return $this->announcement->is_critical ? 'critical' : 'announcements';
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'format' => 'filament',
            'title' => ($this->announcement->is_critical ? __('notifications.critical_prefix').' ' : '').$this->announcement->title,
            'body' => $this->announcement->body,
            'icon' => $this->announcement->is_critical ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-megaphone',
            'iconColor' => $this->announcement->is_critical ? 'danger' : 'info',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'open',
                'label' => __($this->announcement->is_critical ? 'notifications.acknowledge' : 'notifications.open'),
                'url' => '/admin/notification-center',
                'shouldMarkAsRead' => ! $this->announcement->is_critical,
            ]],
            'kind' => $this->announcement->is_critical ? 'critical' : 'announcement',
            'subject' => ['announcement', $this->announcement->id],
        ];
    }
}
