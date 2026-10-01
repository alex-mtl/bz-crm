<?php

declare(strict_types=1);

namespace App\Domain\Events;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Scopes\PersonLocator;
use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Events\Console\EventsTickCommand;
use App\Domain\Events\Models\Event;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Digests;
use App\Domain\Notifications\NotificationCategories;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\PersonReferences;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

final class EventsServiceProvider extends ServiceProvider
{
    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('events.event.created', EventCategory::Business),
            new EventType('events.event.updated', EventCategory::Business),
            new EventType('events.event.cancelled', EventCategory::Business, EventSeverity::Notice),
            new EventType('events.invitation.sent', EventCategory::Business),
            new EventType('events.invitation.bulk_sent', EventCategory::Business),
            new EventType('events.invitation.withdrawn', EventCategory::Business),
            new EventType('events.rsvp.set', EventCategory::Business),
            new EventType('events.attendance.marked', EventCategory::Business),
            new EventType('events.results.published', EventCategory::Business),
            new EventType('events.reminder.sent', EventCategory::Business),
            new EventType('events.feed.created', EventCategory::Security, EventSeverity::Notice),
            new EventType('events.feed.revoked', EventCategory::Security, EventSeverity::Notice),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([EventsTickCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('events:tick')->everyMinute()->withoutOverlapping();
        });

        $this->callAfterResolving(NotificationCategories::class, function (NotificationCategories $categories): void {
            $both = [NotificationCategories::IN_APP, NotificationCategories::EMAIL];
            $categories->register('events', 'events', $both);
            $categories->register('event_reminders', 'events', $both);
        });

        $this->callAfterResolving(PersonReferences::class, function (PersonReferences $references): void {
            $references->register('events', 'organizer_person_id');
            $references->register('event_attendees', 'person_id', ['event_id']);
            $references->register('event_attendees', 'invited_by_person_id');
        });

        $this->callAfterResolving(Digests::class, function (Digests $digests): void {
            // What is coming: the events the reader may see in the week after the period.
            $digests->section('events', function (User $user, Carbon $from, Carbon $to): ?array {
                $events = $this->app->make(EventVisibility::class)->visibleTo($user)->whereNull('events.cancelled_at')
                    ->whereBetween('events.starts_at', [$to, $to->copy()->addDays(7)])->orderBy('events.starts_at')->limit(5)->get();

                return $events->isEmpty() ? null : [
                    'title' => __('events.digest.upcoming'),
                    'lines' => $events->map(fn (Event $event): string => $event->starts_at->isoFormat('D MMM, HH:mm').' — '.$event->title)->all(),
                    'url' => '/admin/events',
                ];
            });
        });

        $this->callAfterResolving(AuthorizationService::class, fn (AuthorizationService $authorization) => $this->configure($authorization));
    }

    private function configure(AuthorizationService $authorization): void
    {
        // An event is managed where its organizer works: a head reaches the events of the people of their scope.
        $authorization->registerLocator(Event::class, new PersonLocator(
            $this->app->make(OrgStructure::class),
            fn (object $event): ?int => $event instanceof Event ? $event->organizer_person_id : null,
            'organizer_person_id',
        ));

        // "Св (организатор)": whoever holds the event runs it — without any system role.
        foreach (['events.update', 'events.invite', 'events.attendance.mark', 'events.results.publish'] as $code) {
            $authorization->addRelation($code, AuthorizationService::RELATION_GRANT,
                fn (User $user, object $event): bool => $event instanceof Event && $event->organizer_person_id === $user->person_id,
                fn (User $user, Builder $query) => $query->where($query->getModel()->qualifyColumn('organizer_person_id'), $user->person_id),
            );
        }
    }
}
