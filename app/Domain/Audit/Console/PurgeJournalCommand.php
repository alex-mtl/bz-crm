<?php

declare(strict_types=1);

namespace App\Domain\Audit\Console;

use App\Domain\Audit\Actions\PurgeExpiredJournalEntries;
use Illuminate\Console\Command;

final class PurgeJournalCommand extends Command
{
    protected $signature = 'journal:purge';

    protected $description = 'Delete journal entries older than the retention period of their category';

    public function handle(PurgeExpiredJournalEntries $purge): int
    {
        $deleted = $purge();

        $this->info($deleted === []
            ? 'Nothing to purge (no expired entries or no retention configured).'
            : 'Purged: '.json_encode($deleted));

        return self::SUCCESS;
    }
}
