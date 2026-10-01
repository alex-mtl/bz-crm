<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Console;

use App\Domain\Audit\JournalContext;
use App\Domain\Notifications\Digests;
use Illuminate\Console\Command;

/**
 * ФО §6.13: the daily and the weekly digest. A repeated run for the same period sends nothing.
 */
final class SendDigestsCommand extends Command
{
    protected $signature = 'notifications:digest {frequency : daily or weekly}';

    protected $description = 'Send the digest of the period that has just ended to those who asked for it';

    public function handle(Digests $digests, JournalContext $context): int
    {
        $frequency = (string) $this->argument('frequency');
        if (! in_array($frequency, [Digests::DAILY, Digests::WEEKLY], true)) {
            $this->error('The frequency is "daily" or "weekly".');

            return self::INVALID;
        }
        $context->asSystem('scheduler:notifications:digest');
        $this->info('Digests sent: '.$digests->run($frequency));

        return self::SUCCESS;
    }
}
