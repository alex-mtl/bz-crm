<?php

declare(strict_types=1);

namespace App\Domain\Events\Notifications;

use App\Domain\Events\EventVisibility;
use App\Domain\Events\Models\Event;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Concerns\RoutesByPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notices about an event to the people invited to it or going to it. They are sent only to those who see the
 * event, and they name it as their subject — so they can be emptied if the invitation is withdrawn.
 */
final class EventNotice extends Notification implements ShouldQueue
{
    use Queueable;
    use RoutesByPreference;

    public const string INVITED = 'invited';

    public const string CHANGED = 'changed';

    public const string CANCELLED = 'cancelled';

    public const string REMINDER = 'reminder';

    public const string RESULTS = 'results';

    public function __construct(public readonly string $kind, public readonly Event $event, public readonly ?string $note = null) {}

    public function category(): string
    {
        return $this->kind === self::REMINDER ? 'event_reminders' : 'events';
    }

    /**
     * Checked at the moment of delivery, not of sending: if the invitation was withdrawn while the notice waited
     * in the queue, nothing is delivered (ТЗ §37).
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! $notifiable instanceof User || app(EventVisibility::class)->canSee($notifiable, $this->event);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $when = $this->event->starts_at->isoFormat('LLL');

        return [
            'format' => 'filament',
            'title' => __('events.notices.'.$this->kind, ['title' => $this->event->title, 'when' => $when]),
            'body' => implode(' · ', array_filter([$when, $this->event->location, $this->note])),
            'icon' => $this->kind === self::CANCELLED ? 'heroicon-o-x-circle' : 'heroicon-o-calendar-days',
            'iconColor' => $this->kind === self::CANCELLED ? 'danger' : 'info',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'open',
                'label' => __('events.notices.open'),
                'url' => '/admin/events/'.$this->event->id,
                'shouldMarkAsRead' => true,
            ]],
            'kind' => 'event_'.$this->kind,
            'event_id' => $this->event->id,
            'subject' => ['event', $this->event->id],
        ];
    }
}
