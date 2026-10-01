<?php

declare(strict_types=1);

namespace App\Filament\Resources\Duplicates\Pages;

use App\Filament\Resources\Duplicates\DuplicateCandidateResource;
use Filament\Resources\Pages\ListRecords;

class ListDuplicateCandidates extends ListRecords
{
    protected static string $resource = DuplicateCandidateResource::class;
}
