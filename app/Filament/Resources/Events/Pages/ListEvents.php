<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Domain\Events\Actions\ManageEvents;
use App\Domain\Identity\Models\User;
use App\Filament\Pages\EventCalendar;
use App\Filament\Resources\Events\EventResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('events.ui.calendar'))->icon('heroicon-o-calendar')->color('gray')->url(EventCalendar::getUrl()),
            Action::make('create')->label(__('events.ui.create'))->icon('heroicon-o-plus')
                ->visible(fn (): bool => EventResource::mayCreate())
                ->schema(EventResource::fields())
                ->action(function (array $data) {
                    $user = Filament::auth()->user();
                    assert($user instanceof User);
                    try {
                        $event = app(ManageEvents::class)->create($user, EventResource::payload($data));
                    } catch (AuthorizationException|DomainException $e) {
                        Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();

                        return null;
                    }
                    Notification::make()->title(__('admin.saved'))->success()->send();

                    return redirect(EventResource::getUrl('view', ['record' => $event]));
                }),
        ];
    }
}
