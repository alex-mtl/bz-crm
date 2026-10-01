<?php

declare(strict_types=1);

namespace App\Filament\Resources\Journal\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Journal\JournalEntryResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListJournalEntries extends ListRecords
{
    public const int EXPORT_LIMIT = 10000;

    protected static string $resource = JournalEntryResource::class;

    public function mount(): void
    {
        parent::mount();

        app(EventJournal::class)->record('audit.viewed', null, [], ['scope' => 'list']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('admin.journal.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => $this->viewerMay('audit.export'))
                ->action(fn (): StreamedResponse => $this->export()),
        ];
    }

    /**
     * CSV of the entries matching the current filters (ADR-006). The export itself is journaled.
     */
    public function export(): StreamedResponse
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);
        app(AuthorizationService::class)->authorize($user, 'audit.export');

        $query = $this->getFilteredSortedTableQuery();
        assert($query !== null);
        $count = min((clone $query)->count(), self::EXPORT_LIMIT);
        app(EventJournal::class)->record('audit.exported', null, [], [
            'rows' => $count,
            'filters' => $this->tableFilters,
        ]);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');
            assert($out !== false);
            fputcsv($out, ['id', 'occurred_at', 'event_type', 'category', 'severity', 'actor_type', 'actor_user_id', 'acting_as',
                'subject_type', 'subject_id', 'old_values', 'new_values', 'context', 'ip_address', 'request_id', 'correlation_id'], escape: '');
            $query->limit(self::EXPORT_LIMIT)->each(function ($entry) use ($out): void {
                assert($entry instanceof JournalEntry);
                fputcsv($out, [
                    $entry->id, $entry->occurred_at->toIso8601String(), $entry->event_type, $entry->category->value,
                    $entry->severity->value, $entry->actor_type->value, $entry->actor_user_id, $entry->acting_as->value,
                    $entry->subject_type, $entry->subject_id,
                    json_encode($entry->old_values, JSON_UNESCAPED_UNICODE), json_encode($entry->new_values, JSON_UNESCAPED_UNICODE),
                    json_encode($entry->context, JSON_UNESCAPED_UNICODE), $entry->ip_address, $entry->request_id, $entry->correlation_id,
                ], escape: '');
            });
            fclose($out);
        }, 'journal-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function viewerMay(string $code): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->can($user, $code);
    }
}
