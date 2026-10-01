<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalogs\Pages;

use App\Filament\Resources\Catalogs\CatalogProposalResource;
use Filament\Resources\Pages\ListRecords;

class ListCatalogProposals extends ListRecords
{
    protected static string $resource = CatalogProposalResource::class;
}
