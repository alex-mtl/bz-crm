<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Console\PurgeJournalCommand;
use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventTypeRegistry::class);
        $this->app->singleton(ValueMasker::class);
        // Per request / per job: must not leak actor or correlation between jobs.
        $this->app->scoped(JournalContext::class);
        $this->app->scoped(EventJournal::class);
    }

    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('audit.viewed', EventCategory::Access),
            new EventType('audit.exported', EventCategory::Access, EventSeverity::Notice),
            new EventType('audit.settings.updated', EventCategory::Admin, EventSeverity::Notice),
            new EventType('audit.retention.purged', EventCategory::Admin, EventSeverity::Notice),
        );

        Queue::createPayloadUsing(fn (): array => [
            'journal' => $this->app->make(JournalContext::class)->toQueuePayload(),
        ]);

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            // A sync job runs inside the dispatching request: it already has the right context.
            if ($event->connectionName === 'sync') {
                return;
            }
            $payload = $event->job->payload()['journal'] ?? null;
            if (is_array($payload)) {
                $this->app->make(JournalContext::class)->restoreFromQueuePayload($payload);
            }
        });

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command === '' || in_array($event->command, ['queue:work', 'queue:listen', 'schedule:work'], true)) {
                return;
            }
            $context = $this->app->make(JournalContext::class);
            $context->asSystem('console:'.$event->command);
            $context->startCorrelation();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeJournalCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('journal:purge')->dailyAt('03:30')->withoutOverlapping();
        });
    }
}
