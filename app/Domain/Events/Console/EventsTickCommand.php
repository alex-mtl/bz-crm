<?php

declare(strict_types=1);

namespace App\Domain\Events\Console;

use App\Domain\Audit\JournalContext;
use App\Domain\Events\Actions\ManageEvents;
use Illuminate\Console\Command;

/**
 * ФО §6.7: reminders to the people going to an event, when their time comes. A repeated run sends nothing twice.
 */
final class EventsTickCommand extends Command
{
    protected $signature = 'events:tick';

    protected $description = 'Send the reminders of events whose time has come';

    public function handle(ManageEvents $events, JournalContext $context): int
    {
        $context->asSystem('scheduler:events:tick');
        $this->info('Reminders sent: '.$events->sendDueReminders());

        return self::SUCCESS;
    }
}
