<?php

declare(strict_types=1);

namespace App\Filament\Resources\Territories;

use App\Domain\Geo\Models\Territory;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Territories\Pages\ListTerritories;
use App\Filament\Resources\Territories\Pages\ViewTerritory;
use App\Filament\Support\Options;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The territory reference (Д-6…Д-9): official names only; traditional forms serve search.
 */
class TerritoryResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Territory::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.organization');
    }

    public static function getModelLabel(): string
    {
        return __('admin.territories.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.territories.plural');
    }

    public static function canViewAny(): bool
    {
        return static::allows('territories.read');
    }

    public static function canView(Model $record): bool
    {
        return static::allows('territories.read');
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
     * "Moldova › Centru › Chișinău" — the ancestors of a node, for orientation in a flat list.
     */
    public static function trail(Territory $territory): string
    {
        $names = Territory::query()->whereKey($territory->ancestorIds())->orderBy('depth')->get()
            ->map(fn (Territory $t): string => $t->name())->all();

        return implode(' › ', $names);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('path')
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))
                    ->state(fn (Territory $record): string => $record->name())
                    ->description(fn (Territory $record): string => static::trail($record))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $w) => (new Territory)->scopeSearch($w, $search))),
                TextColumn::make('level')->label(__('admin.territories.level'))->badge()
                    ->formatStateUsing(fn (string $state): string => Options::catalog('territory_levels')[$state] ?? $state),
                TextColumn::make('children_count')->label(__('admin.territories.children'))->counts('children'),
                IconColumn::make('coordinates')->label(__('admin.territories.coordinates'))->boolean()
                    ->state(fn (Territory $record): bool => $record->latitude !== null),
                IconColumn::make('is_active')->label(__('admin.catalogs.active'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('level')->label(__('admin.territories.level'))->options(fn (): array => Options::catalog('territory_levels')),
                SelectFilter::make('parent_id')->label(__('admin.territories.district'))->searchable()
                    ->options(fn (): array => Territory::query()->whereIn('level', [Territory::MACRO_REGION, Territory::DISTRICT])->orderBy('path')->get()
                        ->mapWithKeys(fn (Territory $t): array => [$t->id => $t->name()])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, function (Builder $query, $id) {
                        $path = Territory::query()->whereKey($id)->value('path');
                        $query->where('path', 'like', $path.'%')->whereKeyNot($id);
                    })),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTerritories::route('/'),
            'view' => ViewTerritory::route('/{record}'),
        ];
    }
}
