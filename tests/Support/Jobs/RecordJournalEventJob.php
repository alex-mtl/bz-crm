<?php

namespace Tests\Support\Jobs;

use App\Domain\Audit\EventJournal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordJournalEventJob implements ShouldQueue
{
    use Queueable;

    public function handle(EventJournal $journal): void
    {
        $journal->record('test.job.ran');
    }
}
