<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Domain\CRM\Exports\Exports;
use App\Filament\Concerns\ExportsRecords;
use App\Filament\Pages\LeadBoard;
use App\Filament\Resources\Leads\LeadResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListLeads extends ListRecords
{
    use ExportsRecords;

    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('board')->label(__('admin.leads.board'))->icon(Heroicon::OutlinedViewColumns)->color('gray')->url(LeadBoard::getUrl()),
            $this->exportAction(Exports::LEADS, 'leads.export', fn (): array => array_filter([
                'pipeline_id' => $this->tableFilters['pipeline_id']['value'] ?? null,
            ])),
            CreateAction::make(),
        ];
    }
}
