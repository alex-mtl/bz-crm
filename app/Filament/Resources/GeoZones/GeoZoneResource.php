<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeoZones;

use App\Domain\Geo\Actions\ManageZones;
use App\Domain\Geo\Models\GeoZone;
use App\Domain\Geo\Models\GeoZoneLink;
use App\Domain\People\Models\Person;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\GeoZones\Pages\ListGeoZones;
use App\Filament\Resources\GeoZones\Pages\ViewGeoZone;
use App\Filament\Support\PersonSearch;
use App\Filament\Support\Places;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Geozones (ФО §6.11): named outlines with people who answer for them and events bound to them.
 * The list is ManageZones::visibleTo(); every change goes through ManageZones.
 */
class GeoZoneResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = GeoZone::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'geo-zones';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.field');
    }

    public static function getModelLabel(): string
    {
        return __('geo.ui.zone');
    }

    public static function getPluralModelLabel(): string
    {
        return __('geo.ui.zones');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('geo_zones.id', app(ManageZones::class)->visibleTo(static::actor())->select('geo_zones.id'));
    }

    public static function canViewAny(): bool
    {
        return static::allows('geo.zones.read');
    }

    public static function canView(Model $record): bool
    {
        return static::allows('geo.zones.read', $record);
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
            TextInput::make('name')->label($g('zone_name'))->required()->maxLength(150),
            $creating ? Places::territory('territory_id')->label($g('territory'))->helperText($g('zone_territory_hint')) : null,
            ColorPicker::make('color')->label($g('color'))->default('#2563eb'),
            Textarea::make('description')->label($g('description'))->rows(2)->maxLength(2000),
            Select::make('responsible_ids')->label($g('responsibles'))->multiple()->searchable()
                ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, activeUsersOnly: true))
                ->getOptionLabelsUsing(fn (array $values): array => Person::query()->whereKey($values)->get()
                    ->mapWithKeys(fn (Person $person): array => [$person->id => $person->fullName()])->all())
                ->helperText($g('responsibles_hint')),
            Toggle::make('notify_events')->label($g('notify_events'))->default(true),
            Toggle::make('notify_crossings')->label($g('notify_crossings'))->default(true),
            $creating ? TextInput::make('latitude')->label($g('center_latitude'))->numeric()->minValue(-90)->maxValue(90)->helperText($g('center_hint')) : null,
            $creating ? TextInput::make('longitude')->label($g('center_longitude'))->numeric()->minValue(-180)->maxValue(180) : null,
        ]));
    }

    public static function table(Table $table): Table
    {
        $g = fn (string $key): string => __('geo.ui.'.$key);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['territory', 'responsibles']))
            ->defaultSort('name')
            ->recordUrl(fn (GeoZone $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                ColorColumn::make('color')->label(''),
                TextColumn::make('name')->label($g('zone_name'))->weight('bold')->searchable()->wrap(),
                TextColumn::make('territory')->label($g('territory'))->state(fn (GeoZone $record): string => $record->territory?->name() ?? $g('whole_organization')),
                TextColumn::make('responsibles')->label($g('responsibles'))->wrap()
                    ->state(fn (GeoZone $record): string => $record->responsibles->map(fn (Person $person): string => $person->fullName())->implode(', '))
                    ->placeholder('—'),
                TextColumn::make('events')->label($g('bound_events'))->alignEnd()
                    ->state(fn (GeoZone $record): int => GeoZoneLink::query()->where('geo_zone_id', $record->id)->where('subject_type', GeoZoneLink::EVENT)->count()),
                TextColumn::make('archived_at')->label(__('admin.fields.status'))->badge()->color('gray')
                    ->formatStateUsing(fn (): string => $g('archived'))->placeholder(''),
            ])
            ->filters([
                Filter::make('active')->label($g('only_active'))->default()->query(fn (Builder $query): Builder => $query->whereNull('geo_zones.archived_at')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGeoZones::route('/'),
            'view' => ViewGeoZone::route('/{record}'),
        ];
    }
}
