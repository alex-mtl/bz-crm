<?php

declare(strict_types=1);

namespace App\Domain\CRM\Exports;

use App\Domain\CRM\Models\ExportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Exports of large datasets run asynchronously (ТЗ §68); the person is notified when the file is ready.
 */
final class BuildExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $batchId) {}

    public function handle(Exports $exports): void
    {
        $batch = ExportBatch::query()->find($this->batchId);
        if ($batch !== null) {
            $exports->build($batch);
            $exports->notifyReady($batch->fresh() ?? $batch);
        }
    }
}
