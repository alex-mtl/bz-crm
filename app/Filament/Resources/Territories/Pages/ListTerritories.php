<?php

declare(strict_types=1);

namespace App\Filament\Resources\Territories\Pages;

use App\Domain\Geo\GeoService;
use App\Filament\Resources\Territories\TerritoryResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ListTerritories extends ListRecords
{
    protected static string $resource = TerritoryResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        // Attribution is required where the data is shown (database/data/geo/README.md).
        return new HtmlString(e(__('admin.territories.sources').' '.GeoService::BOUNDARY_ATTRIBUTION.'; '.GeoService::POINT_ATTRIBUTION));
    }
}
