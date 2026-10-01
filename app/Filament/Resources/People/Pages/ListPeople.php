<?php

declare(strict_types=1);

namespace App\Filament\Resources\People\Pages;

use App\Domain\CRM\Exports\Exports;
use App\Domain\CRM\Models\Segment;
use App\Domain\CRM\Segments;
use App\Domain\Identity\Models\User;
use App\Filament\Concerns\ExportsRecords;
use App\Filament\Resources\Imports\ImportBatchResource;
use App\Filament\Resources\People\PersonResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPeople extends ListRecords
{
    use ExportsRecords;

    protected static string $resource = PersonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // ТЗ §68: an explicit right; only records the viewer sees, and only the fields they may see (Д-13).
            $this->exportAction(Exports::PEOPLE, 'people.export', fn (): array => $this->exportFilters()),
            Action::make('import')->label(__('admin.imports.plural'))->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->visible(fn (): bool => ImportBatchResource::canViewAny())
                ->url(ImportBatchResource::getUrl()),
            CreateAction::make(),
        ];
    }

    /**
     * What the list is filtered by is what gets exported: the chosen segment, or the chosen person type and source.
     *
     * @return array<string, mixed>
     */
    private function exportFilters(): array
    {
        $viewer = Filament::auth()->user();
        $segmentId = $this->tableFilters['segment']['value'] ?? null;
        if ($segmentId !== null && $viewer instanceof User) {
            $segment = app(Segments::class)->visibleTo($viewer)->find($segmentId);
            if ($segment instanceof Segment) {
                return $segment->criteria;
            }
        }

        return array_filter([
            'person_types' => array_filter([$this->tableFilters['person_type']['value'] ?? null]),
            'source_code' => $this->tableFilters['source_code']['value'] ?? null,
            'unit_id' => $this->tableFilters['unit']['value'] ?? null,
        ]);
    }
}
