<?php

declare(strict_types=1);

namespace App\Filament\Resources\Vehicles;

use App\Domain\Geo\Actions\ManageVehicles;
use App\Domain\Geo\Models\Vehicle;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Filament\Support\Options;
use App\Filament\Support\PersonSearch;
use App\Filament\Support\Places;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Vehicles (ФО §6.11, Д-22): a card, a tracker with a key of its own, the last position. The list is
 * ManageVehicles::visibleTo() — the vehicles of the units in the scope of the reader's role.
 */
class VehicleResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Vehicle::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'vehicles';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.field');
    }

    public static function getModelLabel(): string
    {
        return __('geo.ui.vehicle');
    }

    public static function getPluralModelLabel(): string
    {
        return __('geo.ui.vehicles');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('vehicles.id', app(ManageVehicles::class)->visibleTo(static::actor())->select('vehicles.id'));
    }

    public static function canViewAny(): bool
    {
        return static::allows('geo.vehicles.read');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * @return list<Component>
     */
    public static function fields(): array
    {
        $g = fn (string $key): string => __('geo.ui.'.$key);

        return [
            TextInput::make('name')->label($g('vehicle_name'))->required()->maxLength(150),
            TextInput::make('plate')->label($g('plate'))->maxLength(20),
            Select::make('type_code')->label($g('type'))->options(fn (): array => Options::catalog('vehicle_types'))->required()->default('car'),
            Places::unit('org_unit_id')->label($g('unit'))->helperText($g('vehicle_unit_hint')),
            Select::make('responsible_person_id')->label($g('responsible'))->searchable()
                ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search))
                ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
            Textarea::make('description')->label($g('description'))->rows(2)->maxLength(2000),
        ];
    }

    public static function table(Table $table): Table
    {
        $g = fn (string $key): string => __('geo.ui.'.$key);
        $vehicles = fn (): ManageVehicles => app(ManageVehicles::class);
        $manages = fn (Vehicle $record): bool => static::allows('geo.vehicles.manage', $record);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['unit', 'responsible']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label($g('vehicle_name'))->weight('bold')->searchable()
                    ->description(fn (Vehicle $record): string => implode(' · ', array_filter([$record->plate, Options::catalog('vehicle_types')[$record->type_code] ?? $record->type_code]))),
                TextColumn::make('unit')->label($g('unit'))->state(fn (Vehicle $record): ?string => $record->unit?->name)->placeholder('—'),
                TextColumn::make('responsible')->label($g('responsible'))->state(fn (Vehicle $record): ?string => $record->responsible?->fullName())->placeholder('—'),
                IconColumn::make('tracker')->label($g('tracker'))->boolean()->state(fn (Vehicle $record): bool => $record->hasTracker()),
                TextColumn::make('last_point_at')->label($g('last_seen'))->dateTime()->placeholder('—'),
                TextColumn::make('archived_at')->label(__('admin.fields.status'))->badge()->color('gray')
                    ->formatStateUsing(fn (): string => $g('archived'))->placeholder(''),
            ])
            ->filters([
                Filter::make('active')->label($g('only_active'))->default()->query(fn (Builder $query): Builder => $query->whereNull('vehicles.archived_at')),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('edit')->label($g('edit'))->icon('heroicon-o-pencil-square')
                        ->fillForm(fn (Vehicle $record): array => $record->only(['name', 'plate', 'type_code', 'org_unit_id', 'responsible_person_id', 'description']))
                        ->schema(static::fields())
                        ->action(fn (Vehicle $record, array $data) => static::attempt(fn () => $vehicles()->update(static::actor(), $record, $data), __('admin.saved'))),
                    // The key is shown once, here, and is never stored.
                    Action::make('issueKey')->label($g('issue_key'))->icon('heroicon-o-key')->requiresConfirmation()
                        ->modalDescription($g('issue_key_hint'))
                        ->visible(fn (Vehicle $record): bool => $record->archived_at === null)
                        ->action(function (Vehicle $record) use ($vehicles, $g): void {
                            static::attempt(function () use ($record, $vehicles, $g): void {
                                $key = $vehicles()->issueKey(static::actor(), $record);
                                Notification::make()->title($g('key_issued'))->body($key)->persistent()->success()->send();
                            });
                        }),
                    Action::make('revokeKey')->label($g('revoke_key'))->icon('heroicon-o-no-symbol')->requiresConfirmation()->color('danger')
                        ->visible(fn (Vehicle $record): bool => $record->hasTracker())
                        ->action(fn (Vehicle $record) => static::attempt(fn () => $vehicles()->revokeKey(static::actor(), $record), __('admin.saved'))),
                    Action::make('archive')->label(fn (Vehicle $record): string => $record->archived_at === null ? $g('archive') : $g('restore'))
                        ->icon('heroicon-o-archive-box')->requiresConfirmation()->color('danger')
                        ->action(fn (Vehicle $record) => static::attempt(fn () => $vehicles()->archive(static::actor(), $record, $record->archived_at === null), __('admin.saved'))),
                ])->visible($manages),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListVehicles::route('/')];
    }
}
