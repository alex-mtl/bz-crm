<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Console;

use App\Domain\Audit\JournalContext;
use App\Domain\Messaging\Retention;
use Illuminate\Console\Command;

/**
 * Д-27: removes the messages older than the retention term — if a term is set at all.
 */
final class PurgeMessagesCommand extends Command
{
    protected $signature = 'messaging:purge';

    protected $description = 'Remove the messages older than the configured retention term';

    public function handle(Retention $retention, JournalContext $context): int
    {
        $context->asSystem('scheduler:messaging:purge');
        $this->info($retention->months() === null ? 'No retention term is set.' : 'Messages removed: '.$retention->purge());

        return self::SUCCESS;
    }
}
