<?php

declare(strict_types=1);

namespace App\Filament\Resources\Journal\Pages;

use App\Domain\Audit\EventJournal;
use App\Filament\Resources\Journal\JournalEntryResource;
use Filament\Resources\Pages\ViewRecord;

class ViewJournalEntry extends ViewRecord
{
    protected static string $resource = JournalEntryResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        app(EventJournal::class)->record('audit.viewed', null, [], ['entry_id' => $this->getRecord()->getKey()]);
    }
}
