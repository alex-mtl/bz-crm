<?php

declare(strict_types=1);

namespace App\Domain\Geo\Console;

use App\Domain\Audit\JournalContext;
use App\Domain\Geo\Actions\LocationSharing;
use Illuminate\Console\Command;

/**
 * Д-22: the points of shared locations and of vehicle trackers are kept for a limited number of days.
 */
final class PurgeLocationsCommand extends Command
{
    protected $signature = 'geo:purge-locations';

    protected $description = 'Remove location points older than the retention period';

    public function handle(LocationSharing $sharing, JournalContext $context): int
    {
        $context->asSystem('scheduler:geo:purge-locations');
        $this->info('Points removed: '.$sharing->purge());

        return self::SUCCESS;
    }
}
