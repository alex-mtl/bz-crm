<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Console;

use App\Domain\Audit\JournalContext;
use App\Domain\Messaging\Actions\SendMessages;
use Illuminate\Console\Command;

/**
 * ФО §6.6.1 "отложенная отправка": messages scheduled for later are sent when their time comes.
 */
final class MessagingTickCommand extends Command
{
    protected $signature = 'messaging:tick';

    protected $description = 'Send the scheduled messages whose time has come';

    public function handle(SendMessages $messages, JournalContext $context): int
    {
        $context->asSystem('scheduler:messaging:tick');
        $this->info('Messages sent: '.$messages->sendScheduled());

        return self::SUCCESS;
    }
}
