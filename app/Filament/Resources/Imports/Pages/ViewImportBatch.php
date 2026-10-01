<?php

declare(strict_types=1);

namespace App\Filament\Resources\Imports\Pages;

use App\Domain\CRM\Imports\PeopleImport;
use App\Domain\CRM\Models\ImportBatch;
use App\Domain\CRM\Models\ImportRow;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Imports\ImportBatchResource;
use App\Filament\Support\Options;
use App\Filament\Support\Places;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * One import: the verdict of the dry run (the preview), then "import" — or the error report to fix the file.
 */
class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    public const int PREVIEW_ROWS = 100;

    public function getTitle(): string
    {
        return $this->batch()->original_name;
    }

    private function batch(): ImportBatch
    {
        $record = $this->getRecord();
        assert($record instanceof ImportBatch);

        return $record;
    }

    private function actor(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    private function run(callable $action, ?string $success = null): void
    {
        try {
            $action();
            Notification::make()->title($success ?? __('admin.saved'))->success()->send();
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();
        }
        $this->record = $this->batch()->fresh() ?? $this->batch();
    }

    protected function getHeaderActions(): array
    {
        $i = fn (string $key): string => __('admin.imports.'.$key);
        $problems = fn (): bool => (($this->batch()->totals['errors'] ?? 0) + ($this->batch()->totals['duplicates'] ?? 0)) > 0;

        return [
            Action::make('commit')->label($i('commit'))->icon('heroicon-o-check')->color('success')
                ->visible(fn (): bool => $this->batch()->status === ImportBatch::VALIDATED && ($this->batch()->totals['errors'] ?? 0) === 0)
                ->requiresConfirmation()
                ->modalDescription(fn (): string => __('admin.imports.commit_hint', [
                    'count' => ($this->batch()->totals['valid'] ?? 0) + (($this->batch()->options['duplicates'] ?? 'skip') === 'create' ? ($this->batch()->totals['duplicates'] ?? 0) : 0),
                ]))
                ->action(fn () => $this->run(fn () => app(PeopleImport::class)->commit($this->actor(), $this->batch()), $i('started'))),
            Action::make('report')->label($i('report'))->icon('heroicon-o-document-arrow-down')->color('gray')
                ->visible($problems)
                ->url(fn (): string => route('imports.report', $this->batch())),
            Action::make('revalidate')->label($i('revalidate'))->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn (): bool => in_array($this->batch()->status, [ImportBatch::VALIDATED, ImportBatch::FAILED], true))
                ->action(fn () => $this->run(fn () => app(PeopleImport::class)->validate($this->actor(), $this->batch()))),
            Action::make('rollback')->label($i('rollback'))->icon('heroicon-o-arrow-uturn-left')->color('danger')
                ->visible(fn (): bool => $this->batch()->status === ImportBatch::COMPLETED)
                ->requiresConfirmation()->modalDescription($i('rollback_hint'))
                ->action(fn () => $this->run(fn () => app(PeopleImport::class)->rollback($this->actor(), $this->batch()))),
        ];
    }

    /**
     * @return Collection<int, ImportRow>
     */
    private function previewRows(): Collection
    {
        $problems = $this->batch()->rows()->whereIn('status', [ImportRow::ERROR, ImportRow::DUPLICATE])->limit(self::PREVIEW_ROWS)->get();
        $rest = $this->batch()->rows()->whereNotIn('status', [ImportRow::ERROR, ImportRow::DUPLICATE])
            ->limit(max(0, self::PREVIEW_ROWS - $problems->count()))->get();

        return $problems->concat($rest)->values();
    }

    public function infolist(Schema $schema): Schema
    {
        $batch = $this->batch();
        $i = fn (string $key): string => __('admin.imports.'.$key);
        $totals = $batch->totals ?? [];
        $options = $batch->options ?? [];
        $number = fn (string $key): TextEntry => TextEntry::make('total_'.$key)->label($i($key))->state((string) ($totals[$key] ?? 0));

        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('status')->label(__('admin.fields.status'))->badge()
                    ->state($i('statuses.'.$batch->status))->color(ImportBatchResource::statusColor($batch->status)),
                $number('total'),
                $number('valid'),
                $number('errors')->color(($totals['errors'] ?? 0) > 0 ? 'danger' : null),
                $number('duplicates')->color(($totals['duplicates'] ?? 0) > 0 ? 'warning' : null),
                $number('imported'),
                TextEntry::make('default_type')->label($i('default_type'))->state(Options::catalog('person_types')[(string) ($options['person_type'] ?? '')] ?? '—'),
                TextEntry::make('unit')->label(__('admin.people.responsible_unit'))->state(Places::unitName($options['responsible_unit_id'] ?? null))->placeholder('—'),
                TextEntry::make('on_duplicates')->label($i('on_duplicates'))->state($i('duplicates_'.($options['duplicates'] ?? 'skip'))),
                TextEntry::make('failure')->label($i('failure'))->state($batch->failure)->visible($batch->failure !== null)->color('danger')->columnSpanFull(),
                TextEntry::make('blocked')->hiddenLabel()->columnSpanFull()->color('danger')
                    ->visible($batch->status === ImportBatch::VALIDATED && ($totals['errors'] ?? 0) > 0)
                    ->state($i('blocked_by_errors')),
            ]),
            Section::make($i('preview'))->description(__('admin.imports.preview_hint', ['count' => self::PREVIEW_ROWS]))->schema([
                RepeatableEntry::make('rows')->hiddenLabel()->columns(4)
                    // Problem rows first: that is what the person came to see.
                    ->state($this->previewRows()
                        ->map(fn (ImportRow $row): array => [
                            'row' => '#'.$row->row_number,
                            'name' => trim(($row->data['first_name'] ?? '').' '.($row->data['last_name'] ?? '')) ?: '—',
                            'status' => __('crm.import.row_statuses.'.$row->status),
                            'problems' => implode('; ', $row->errors ?? []),
                        ])->all())
                    ->schema([
                        TextEntry::make('row')->hiddenLabel(),
                        TextEntry::make('name')->hiddenLabel()->weight('bold'),
                        TextEntry::make('status')->hiddenLabel()->badge()->color('gray'),
                        TextEntry::make('problems')->hiddenLabel()->placeholder(''),
                    ]),
            ]),
        ]);
    }
}
