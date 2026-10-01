<?php

declare(strict_types=1);

namespace App\Filament\Resources\Moderation\Pages;

use App\Filament\Resources\Moderation\ReportResource;
use App\Filament\Resources\Moderation\SanctionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListReports extends ListRecords
{
    protected static string $resource = ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sanctions')->label(__('social.moderation.sanctions'))->icon('heroicon-o-clipboard-document-list')->color('gray')
                ->visible(fn (): bool => SanctionResource::canViewAny())
                ->url(SanctionResource::getUrl('index')),
        ];
    }
}
