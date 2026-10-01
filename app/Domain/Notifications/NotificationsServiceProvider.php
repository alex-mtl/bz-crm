<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Notifications\Channels\DatabaseChannel;
use App\Domain\Notifications\Console\SendDigestsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\Channels\DatabaseChannel as BaseDatabaseChannel;
use Illuminate\Support\ServiceProvider;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationCategories::class, function (): NotificationCategories {
            $categories = new NotificationCategories;
            $both = [NotificationCategories::IN_APP, NotificationCategories::EMAIL];
            // The platform's own categories; the modules add theirs in their service providers.
            $categories->register('security', 'identity', $both, mandatory: true);
            $categories->register('critical', 'notifications', $both, mandatory: true);
            $categories->register('announcements', 'notifications', $both);
            $categories->register('digest_daily', 'notifications', []);
            $categories->register('digest_weekly', 'notifications', $both);

            return $categories;
        });
        $this->app->scoped(Preferences::class);
        $this->app->singleton(Digests::class);
        $this->app->bind(BaseDatabaseChannel::class, DatabaseChannel::class);
    }

    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('notifications.announcement.sent', EventCategory::Business),
            new EventType('notifications.critical.sent', EventCategory::Security, EventSeverity::Warning),
            new EventType('notifications.critical.acknowledged', EventCategory::Security, EventSeverity::Notice),
            new EventType('notifications.defaults.changed', EventCategory::Security, EventSeverity::Notice),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([SendDigestsCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('notifications:digest daily')->dailyAt('07:00')->withoutOverlapping();
            $schedule->command('notifications:digest weekly')->weeklyOn(1, '07:30')->withoutOverlapping();
        });
    }
}
