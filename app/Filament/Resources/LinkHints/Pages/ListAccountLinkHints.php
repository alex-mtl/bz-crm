<?php

declare(strict_types=1);

namespace App\Filament\Resources\LinkHints\Pages;

use App\Filament\Resources\LinkHints\AccountLinkHintResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountLinkHints extends ListRecords
{
    protected static string $resource = AccountLinkHintResource::class;
}
