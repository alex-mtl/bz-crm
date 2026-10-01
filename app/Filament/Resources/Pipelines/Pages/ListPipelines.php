<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pipelines\Pages;

use App\Filament\Resources\Pipelines\PipelineResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPipelines extends ListRecords
{
    protected static string $resource = PipelineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label(__('admin.pipelines.create'))->icon('heroicon-o-plus')
                ->schema(PipelineResource::fields())
                ->action(fn (array $data) => PipelineResource::save($data)),
        ];
    }
}
