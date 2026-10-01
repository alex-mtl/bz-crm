<?php

declare(strict_types=1);

namespace App\Domain\CRM\Imports;

use App\Domain\CRM\Models\ImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Large imports run through the queue (ТЗ §68). One attempt: the import is a single transaction, and a failed
 * one is reported on the batch instead of being retried blindly.
 */
final class RunPeopleImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $batchId) {}

    public function handle(PeopleImport $import): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        if ($batch !== null) {
            $import->run($batch);
        }
    }
}
