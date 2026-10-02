<?php

declare(strict_types=1);

namespace App\Filament\Resources\Houses;

use App\Domain\Geo\CanvassSummary;
use App\Domain\Geo\FieldAccess;
use App\Domain\Geo\Models\FieldAssignment;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\Territory;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Houses\Pages\ListHouses;
use App\Filament\Resources\Houses\Pages\ViewHouse;
use App\Filament\Support\Options;
use App\Filament\Support\Places;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Houses of the field work (ФО §6.11). The list is FieldAccess::houses(): a head sees the houses of the scope of
 * their role, an agitator — the houses they answer for. Every change goes through the domain actions.
 */
class HouseResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = House::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'houses';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.field');
    }

    public static function getModelLabel(): string
    {
        return __('geo.ui.house');
    }

    public static function getPluralModelLabel(): string
    {
        return __('geo.ui.houses');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('houses.id', app(FieldAccess::class)->houses(static::actor())->select('houses.id'));
    }

    public static function canViewAny(): bool
    {
        return static::allows('geo.houses.read');
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof House && app(FieldAccess::class)->can(static::actor(), 'geo.houses.read', $record);
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
    public static function fields(bool $creating = true): array
    {
        $g = fn (string $key): string => __('geo.ui.'.$key);

        return array_values(array_filter([
            Places::territory('territory_id')->label($g('territory'))->required()->helperText($g('territory_hint')),
            $creating ? TextInput::make('street')->label($g('street'))->required()->maxLength(150)->helperText($g('street_hint')) : null,
            $creating ? TextInput::make('number')->label($g('number'))->required()->maxLength(30) : null,
            $creating ? Select::make('type_code')->label($g('type'))->options(fn (): array => Options::catalog('house_types'))
                ->default(House::APARTMENT_BUILDING)->required() : null,
            TextInput::make('entrances')->label($g('entrances'))->numeric()->minValue(0)->maxValue(100),
            TextInput::make('floors')->label($g('floors'))->numeric()->minValue(0)->maxValue(200),
            $creating ? TextInput::make('apartments')->label($g('apartments_count'))->numeric()->minValue(0)->maxValue(2000)->helperText($g('apartments_hint')) : null,
            TextInput::make('residents_count')->label($g('residents'))->numeric()->minValue(0),
            TextInput::make('latitude')->label($g('latitude'))->numeric()->minValue(-90)->maxValue(90),
            TextInput::make('longitude')->label($g('longitude'))->numeric()->minValue(-180)->maxValue(180),
            Textarea::make('description')->label($g('description'))->rows(2)->maxLength(2000),
        ]));
    }

    public static function table(Table $table): Table
    {
        $g = fn (string $key): string => __('geo.ui.'.$key);
        $figures = fn (House $record): array => app(CanvassSummary::class)->forHouse($record);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['address.street', 'territory'])->withCount('apartments'))
            ->defaultSort('id')
            ->recordUrl(fn (House $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('address')->label($g('address'))->weight('bold')->state(fn (House $record): string => $record->label())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('address', fn (Builder $address) => $address
                        ->where('number', 'like', "%{$search}%")
                        ->orWhereHas('street', fn (Builder $street) => $street->where('name', 'like', "%{$search}%")))),
                TextColumn::make('territory')->label($g('territory'))->state(fn (House $record): string => $record->territory->name()),
                TextColumn::make('type_code')->label($g('type'))->badge()->color('gray')
                    ->formatStateUsing(fn (string $state): string => Options::catalog('house_types')[$state] ?? $state),
                TextColumn::make('apartments_count')->label($g('apartments'))->alignEnd(),
                TextColumn::make('visited')->label($g('visited'))->alignEnd()->state(fn (House $record): string => $figures($record)['visited_pct'].'%'),
                TextColumn::make('supporters')->label($g('supporters'))->alignEnd()->state(fn (House $record): string => $figures($record)['supporter_pct'].'%'),
                TextColumn::make('agitators')->label($g('agitators'))->wrap()
                    ->state(fn (House $record): string => FieldAssignment::query()->current()->where('house_id', $record->id)->with('person')->get()
                        ->map(fn (FieldAssignment $assignment): string => $assignment->person->fullName())->implode(', '))
                    ->placeholder('—'),
                TextColumn::make('archived_at')->label(__('admin.fields.status'))->badge()->color('gray')
                    ->formatStateUsing(fn (): string => $g('archived'))->placeholder(''),
            ])
            ->filters([
                Filter::make('active')->label($g('only_active'))->default()->query(fn (Builder $query): Builder => $query->whereNull('houses.archived_at')),
                Filter::make('mine')->label($g('my_houses'))->query(function (Builder $query): Builder {
                    app(FieldAccess::class)->whereAssigned($query, static::actor()->person_id);

                    return $query;
                }),
                SelectFilter::make('territory_id')->label($g('territory'))
                    ->options(fn (): array => Territory::query()->whereIn('id', static::getEloquentQuery()->select('houses.territory_id'))->orderBy('path')->get()
                        ->mapWithKeys(fn (Territory $territory): array => [$territory->id => $territory->name()])->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHouses::route('/'),
            'view' => ViewHouse::route('/{record}'),
        ];
    }
}
