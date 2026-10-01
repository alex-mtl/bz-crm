<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrgUnits\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Actions\ManageOrgUnits;
use App\Domain\Organization\Models\OrgUnit;
use App\Filament\Resources\OrgUnits\OrgUnitResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;

class ListOrgUnits extends ListRecords
{
    protected static string $resource = OrgUnitResource::class;

    protected function getHeaderActions(): array
    {
        $actor = Filament::auth()->user();
        assert($actor instanceof User);

        return [
            Action::make('create')->label(__('admin.org_units.create'))
                ->visible(fn (): bool => app(AuthorizationService::class)->can($actor, 'org_units.manage'))
                ->schema([
                    TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(150),
                    Select::make('parent_id')->label(__('admin.org_units.parent'))
                        ->options(fn (): array => OrgUnit::query()->active()->orderBy('path')->get()
                            ->mapWithKeys(fn (OrgUnit $u): array => [$u->id => str_repeat('— ', $u->depth).$u->name])->all()),
                    Textarea::make('description')->label(__('admin.territories.description')),
                ])
                ->action(function (array $data) use ($actor): void {
                    try {
                        $parent = isset($data['parent_id']) ? OrgUnit::query()->find($data['parent_id']) : null;
                        $unit = app(ManageOrgUnits::class)->create($actor, (string) $data['name'], $parent, $data['description'] ?? null);
                        $this->redirect(OrgUnitResource::getUrl('view', ['record' => $unit]));
                    } catch (AuthorizationException|DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
