<?php

declare(strict_types=1);

namespace App\Filament\Resources\Applications\Pages;

use App\Filament\Resources\Applications\RegistrationApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListRegistrationApplications extends ListRecords
{
    protected static string $resource = RegistrationApplicationResource::class;
}
