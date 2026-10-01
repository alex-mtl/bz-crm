<?php

declare(strict_types=1);

namespace App\Domain\Geo\Console;

use App\Domain\Geo\Actions\ImportTerritories;
use Illuminate\Console\Command;

/**
 * Idempotent: loads or refreshes the official territory reference in any environment, production included.
 */
final class ImportTerritoriesCommand extends Command
{
    protected $signature = 'geo:import-territories {--path= : directory with the reference files (default: database/data/geo)}';

    protected $description = 'Load or refresh the official territory reference of Moldova (Д-6…Д-9)';

    public function handle(ImportTerritories $import): int
    {
        $path = $this->option('path');
        $stats = $import(is_string($path) && $path !== '' ? $path : null);
        $this->info(sprintf('Territories: %d total, %d created, %d updated.', $stats['total'], $stats['created'], $stats['updated']));

        return self::SUCCESS;
    }
}
