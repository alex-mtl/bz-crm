<?php

declare(strict_types=1);

namespace App\Filament\Resources\Streets\Pages;

use App\Filament\Resources\Streets\StreetResource;
use Filament\Resources\Pages\ListRecords;

class ListStreets extends ListRecords
{
    protected static string $resource = StreetResource::class;
}
