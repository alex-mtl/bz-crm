<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrgUnits\RelationManagers;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Actions\ManageMembership;
use App\Domain\Organization\Models\OrgMembership;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\People\Models\Person;
use App\Filament\Resources\People\PersonResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Members of the unit with their direct managers (Д-11). Actions go through ManageMembership.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.org_units.members');
    }

    private function actor(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    private function run(callable $action): void
    {
        try {
            $action();
            Notification::make()->title(__('admin.saved'))->success()->send();
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function table(Table $table): Table
    {
        $unit = $this->getOwnerRecord();
        assert($unit instanceof OrgUnit);
        $may = fn (string $code, OrgMembership $m): bool => app(AuthorizationService::class)->can($this->actor(), $code, $m->person);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['person', 'manager']))
            ->recordTitleAttribute('person_id')
            ->columns([
                TextColumn::make('person.first_name')->label(__('admin.fields.name'))
                    ->formatStateUsing(fn (OrgMembership $record): string => $record->person->fullName())
                    ->url(fn (OrgMembership $record): ?string => PersonResource::canView($record->person) ? PersonResource::getUrl('view', ['record' => $record->person]) : null),
                TextColumn::make('position')->label(__('admin.org_units.position'))->placeholder('—'),
                TextColumn::make('manager')->label(__('admin.org_units.manager'))->placeholder('—')
                    ->state(fn (OrgMembership $record): ?string => $record->manager?->fullName()),
                IconColumn::make('manager_set_manually')->label(__('admin.org_units.manager_manual'))->boolean(),
                IconColumn::make('is_head')->label(__('admin.org_units.head'))->boolean()
                    ->state(fn (OrgMembership $record): bool => $unit->head_person_id === $record->person_id),
                TextColumn::make('joined_at')->label(__('admin.org_units.joined'))->date(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('manager')->label(__('admin.org_units.change_manager'))
                        ->visible(fn (OrgMembership $record): bool => $may('people.manager.change', $record))
                        ->schema(fn (OrgMembership $record): array => [
                            Select::make('manager_id')->label(__('admin.org_units.manager'))->required()->searchable()
                                ->options(fn (): array => OrgMembership::query()->with('person')
                                    ->whereIn('org_unit_id', OrgUnit::query()->withinPath($record->unit->path)->select('id'))
                                    ->where('person_id', '!=', $record->person_id)->get()
                                    ->mapWithKeys(fn (OrgMembership $m): array => [$m->person_id => $m->person->fullName()])->all()),
                        ])
                        ->action(fn (OrgMembership $record, array $data) => $this->run(fn () => app(ManageMembership::class)->changeManager(
                            $this->actor(), $record->person, Person::query()->findOrFail($data['manager_id']),
                        ))),
                    Action::make('transfer')->label(__('admin.org_units.transfer'))
                        ->visible(fn (OrgMembership $record): bool => $may('people.transfer', $record))
                        ->schema([
                            Select::make('unit_id')->label(__('admin.org_units.singular'))->required()
                                ->options(fn (): array => OrgUnit::query()->active()->orderBy('path')->get()
                                    ->mapWithKeys(fn (OrgUnit $u): array => [$u->id => str_repeat('— ', $u->depth).$u->name])->all()),
                            TextInput::make('reason')->label(__('admin.org_units.reason'))->required()->maxLength(255),
                        ])
                        ->action(fn (OrgMembership $record, array $data) => $this->run(fn () => app(ManageMembership::class)->transfer(
                            $this->actor(), $record->person, OrgUnit::query()->findOrFail($data['unit_id']), (string) $data['reason'],
                        ))),
                    Action::make('position')->label(__('admin.org_units.set_position'))
                        ->visible(fn (OrgMembership $record): bool => $may('people.update', $record))
                        ->fillForm(fn (OrgMembership $record): array => ['position' => $record->position])
                        ->schema([TextInput::make('position')->label(__('admin.org_units.position'))->maxLength(150)])
                        ->action(fn (OrgMembership $record, array $data) => $this->run(fn () => app(ManageMembership::class)->setPosition(
                            $this->actor(), $record->person, $data['position'] ?? null,
                        ))),
                ]),
            ]);
    }
}
