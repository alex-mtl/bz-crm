<?php

declare(strict_types=1);

namespace App\Filament\Resources\Segments\Pages;

use App\Filament\Resources\Segments\SegmentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListSegments extends ListRecords
{
    protected static string $resource = SegmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label(__('admin.segments.create'))->icon('heroicon-o-plus')
                ->schema(SegmentResource::fields())
                ->action(fn (array $data) => SegmentResource::save($data)),
        ];
    }
}
