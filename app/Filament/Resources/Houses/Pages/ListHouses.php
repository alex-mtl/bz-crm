<?php

declare(strict_types=1);

namespace App\Filament\Resources\Houses\Pages;

use App\Domain\Geo\Actions\ManageHouses;
use App\Domain\Identity\Models\User;
use App\Filament\Pages\FieldMap;
use App\Filament\Pages\FieldSummary;
use App\Filament\Resources\Houses\HouseResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;

class ListHouses extends ListRecords
{
    protected static string $resource = HouseResource::class;

    protected function getHeaderActions(): array
    {
        $g = fn (string $key): string => __('geo.ui.'.$key);

        return [
            Action::make('app')->label($g('open_app'))->icon('heroicon-o-device-phone-mobile')->color('gray')->url('/field')
                ->visible(fn (): bool => (bool) Filament::auth()->user()?->can('geo.visits.create')),
            Action::make('map')->label($g('map'))->icon('heroicon-o-map')->color('gray')->url(fn (): string => FieldMap::getUrl())
                ->visible(fn (): bool => FieldMap::canAccess()),
            Action::make('summary')->label($g('summary'))->icon('heroicon-o-chart-bar')->color('gray')->url(fn (): string => FieldSummary::getUrl())
                ->visible(fn (): bool => FieldSummary::canAccess()),
            Action::make('create')->label($g('add_house'))->icon('heroicon-o-plus')
                ->visible(fn (): bool => (bool) Filament::auth()->user()?->can('geo.houses.manage'))
                ->schema(HouseResource::fields())
                ->action(function (array $data) {
                    $user = Filament::auth()->user();
                    assert($user instanceof User);
                    try {
                        $house = app(ManageHouses::class)->create($user, [
                            ...$data, 'territory_id' => (int) $data['territory_id'],
                            'apartments' => filled($data['apartments'] ?? null) ? (int) $data['apartments'] : null,
                        ]);
                    } catch (AuthorizationException|DomainException $e) {
                        Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();

                        return null;
                    }
                    Notification::make()->title(__('admin.saved'))->success()->send();

                    return redirect(HouseResource::getUrl('view', ['record' => $house]));
                }),
        ];
    }
}
