<?php

declare(strict_types=1);

namespace App\Filament\Resources\Vehicles\Pages;

use App\Domain\Geo\Actions\ManageVehicles;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Vehicles\VehicleResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;

class ListVehicles extends ListRecords
{
    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label(__('geo.ui.add_vehicle'))->icon('heroicon-o-plus')
                ->visible(fn (): bool => (bool) Filament::auth()->user()?->can('geo.vehicles.manage'))
                ->schema(VehicleResource::fields())
                ->action(function (array $data): void {
                    $user = Filament::auth()->user();
                    assert($user instanceof User);
                    try {
                        app(ManageVehicles::class)->create($user, $data);
                    } catch (AuthorizationException|DomainException $e) {
                        Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();

                        return;
                    }
                    Notification::make()->title(__('admin.saved'))->success()->send();
                }),
        ];
    }
}
