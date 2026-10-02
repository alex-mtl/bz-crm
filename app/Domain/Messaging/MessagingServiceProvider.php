<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Messaging\Console\MessagingTickCommand;
use App\Domain\Messaging\Console\PurgeMessagesCommand;
use App\Domain\Notifications\NotificationCategories;
use App\Domain\People\PersonReferences;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChatSubjects::class);
        $this->app->scoped(ChatAccess::class);
        $this->app->scoped(DirectMessagePolicy::class);
    }

    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('messaging.chat.created', EventCategory::Business),
            new EventType('messaging.chat.archived', EventCategory::Business),
            new EventType('messaging.member.added', EventCategory::Business),
            new EventType('messaging.member.removed', EventCategory::Business),
            new EventType('messaging.member.role_changed', EventCategory::Business),
            new EventType('messaging.link.created', EventCategory::Security, EventSeverity::Notice),
            new EventType('messaging.link.revoked', EventCategory::Security, EventSeverity::Notice),
            new EventType('messaging.message.deleted', EventCategory::Security, EventSeverity::Notice),
            new EventType('messaging.attachment.infected', EventCategory::Security, EventSeverity::Warning),
            new EventType('messaging.policy.changed', EventCategory::Security, EventSeverity::Notice),
            new EventType('messaging.retention.changed', EventCategory::Security, EventSeverity::Warning),
            new EventType('messaging.retention.purged', EventCategory::Data, EventSeverity::Warning),
            new EventType('messaging.chat.investigated', EventCategory::Access, EventSeverity::Warning),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([MessagingTickCommand::class, PurgeMessagesCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('messaging:tick')->everyMinute()->withoutOverlapping();
            $schedule->command('messaging:purge')->dailyAt('03:30')->withoutOverlapping();
        });

        $this->callAfterResolving(NotificationCategories::class, function (NotificationCategories $categories): void {
            $categories->register('messages', 'messaging');
            $categories->register('mentions', 'messaging', [NotificationCategories::IN_APP, NotificationCategories::EMAIL]);
        });

        $this->callAfterResolving(PersonReferences::class, function (PersonReferences $references): void {
            $references->register('chat_members', 'person_id', ['chat_id']);
            $references->register('messages', 'author_person_id');
            $references->register('messages', 'pinned_by_person_id');
            $references->register('message_reactions', 'person_id', ['message_id']);
            $references->register('message_mentions', 'person_id', ['message_id']);
            $references->register('message_poll_votes', 'person_id', ['message_id']);
            $references->register('chats', 'created_by_person_id');
            $references->register('chat_invitations', 'created_by_person_id');
        });
    }
}
