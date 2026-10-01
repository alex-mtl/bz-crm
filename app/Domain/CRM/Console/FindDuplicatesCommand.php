<?php

declare(strict_types=1);

namespace App\Domain\CRM\Console;

use App\Domain\CRM\Duplicates;
use Illuminate\Console\Command;

/**
 * Re-checks the whole registry for possible duplicates (ФО §6.9.1): catches pairs that appeared through paths
 * that do not raise the check themselves.
 */
final class FindDuplicatesCommand extends Command
{
    protected $signature = 'crm:find-duplicates';

    protected $description = 'Scan the people registry for possible duplicates';

    public function handle(Duplicates $duplicates): int
    {
        $this->info('New pairs: '.$duplicates->scanAll());

        return self::SUCCESS;
    }
}
