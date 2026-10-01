<?php

declare(strict_types=1);

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListAnnouncements extends ListRecords
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')->label(__('notifications.ui.send'))->icon('heroicon-o-megaphone')
                ->modalDescription(__('notifications.ui.send_hint'))
                ->schema(AnnouncementResource::fields())
                ->action(fn (array $data) => AnnouncementResource::send($data)),
        ];
    }
}
