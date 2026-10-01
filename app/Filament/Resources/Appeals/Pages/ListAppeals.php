<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appeals\Pages;

use App\Domain\CRM\Exports\Exports;
use App\Filament\Concerns\ExportsRecords;
use App\Filament\Resources\Appeals\AppealResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAppeals extends ListRecords
{
    use ExportsRecords;

    protected static string $resource = AppealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->exportAction(Exports::APPEALS, 'appeals.export', fn (): array => []),
            CreateAction::make(),
        ];
    }
}
