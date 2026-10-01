<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomFields\Pages;

use App\Filament\Resources\CustomFields\CustomFieldResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListCustomFields extends ListRecords
{
    protected static string $resource = CustomFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label(__('admin.custom_fields.create'))->icon('heroicon-o-plus')
                ->schema(CustomFieldResource::fields(true))
                ->action(fn (array $data) => CustomFieldResource::save($data)),
        ];
    }
}
