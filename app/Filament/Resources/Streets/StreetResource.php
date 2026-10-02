<?php

declare(strict_types=1);

namespace App\Filament\Resources\Streets;

use App\Domain\Geo\Actions\ManageAddresses;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\Street;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Streets\Pages\ListStreets;
use App\Filament\Support\Options;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The address directory (ФО §6.11): one street — one entry. Streets appear by themselves when houses are added;
 * here those who keep the directory correct a spelling and merge two entries of one street.
 */
class StreetResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Street::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'streets';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.field');
    }

    public static function getModelLabel(): string
    {
        return __('geo.ui.street');
    }

    public static function getPluralModelLabel(): string
    {
        return __('geo.ui.address_directory');
    }

    public static function canViewAny(): bool
    {
        return static::allows('geo.addresses.manage');
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

    public static function table(Table $table): Table
    {
        $g = fn (string $key): string => __('geo.ui.'.$key);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('territory')->withCount('addresses'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label($g('street'))->weight('bold')->searchable(['name', 'normalized'])->sortable(),
                TextColumn::make('type_code')->label($g('street_type'))->badge()->color('gray')
                    ->formatStateUsing(fn (string $state): string => Options::catalog('street_types')[$state] ?? $state),
                TextColumn::make('territory')->label($g('settlement'))->state(fn (Street $record): string => $record->territory->name()),
                TextColumn::make('addresses_count')->label($g('addresses'))->alignEnd(),
                TextColumn::make('houses')->label($g('houses'))->alignEnd()
                    ->state(fn (Street $record): int => House::query()->whereIn('address_id', $record->addresses()->select('id'))->count()),
            ])
            ->recordActions([
                Action::make('rename')->label($g('rename'))->icon('heroicon-o-pencil-square')
                    ->fillForm(fn (Street $record): array => ['name' => $record->name])
                    ->schema([TextInput::make('name')->label($g('street'))->required()->maxLength(150)->helperText($g('street_hint'))])
                    ->action(fn (Street $record, array $data) => static::attempt(
                        fn () => app(ManageAddresses::class)->rename(static::actor(), $record, (string) $data['name']), __('admin.saved'),
                    )),
                Action::make('merge')->label($g('merge'))->icon('heroicon-o-arrows-pointing-in')->color('gray')
                    ->modalDescription($g('merge_hint'))
                    ->schema([
                        Select::make('into')->label($g('merge_into'))->required()->searchable()
                            ->options(fn (Street $record): array => Street::query()->where('territory_id', $record->territory_id)->whereKeyNot($record->id)
                                ->orderBy('name')->pluck('name', 'id')->all()),
                    ])
                    ->action(fn (Street $record, array $data) => static::attempt(
                        fn () => app(ManageAddresses::class)->merge(static::actor(), $record, Street::query()->findOrFail($data['into'])), __('admin.saved'),
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStreets::route('/')];
    }
}
