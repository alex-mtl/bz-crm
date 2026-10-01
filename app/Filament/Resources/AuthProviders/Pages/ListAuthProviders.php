<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuthProviders\Pages;

use App\Filament\Resources\AuthProviders\AuthProviderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAuthProviders extends ListRecords
{
    protected static string $resource = AuthProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
