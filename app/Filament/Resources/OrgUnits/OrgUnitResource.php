<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrgUnits;

use App\Domain\Organization\Models\OrgUnit;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\OrgUnits\Pages\ListOrgUnits;
use App\Filament\Resources\OrgUnits\Pages\ViewOrgUnit;
use App\Filament\Resources\OrgUnits\RelationManagers\MembersRelationManager;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The unit tree (ФО §7): heads, territories (Д-3), members and their direct managers (Д-11).
 */
class OrgUnitResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = OrgUnit::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'org-units';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.organization');
    }

    public static function getModelLabel(): string
    {
        return __('admin.org_units.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.org_units.plural');
    }

    public static function canViewAny(): bool
    {
        return static::allows('org_units.read');
    }

    public static function canView(Model $record): bool
    {
        return static::allows('org_units.read');
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
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['head', 'territories'])->withCount('memberships'))
            ->defaultSort('path')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))
                    ->formatStateUsing(fn (OrgUnit $record): string => str_repeat('— ', $record->depth).$record->name)
                    ->searchable(),
                TextColumn::make('head')->label(__('admin.org_units.head'))->placeholder('—')
                    ->state(fn (OrgUnit $record): ?string => $record->head?->fullName()),
                TextColumn::make('territories')->label(__('admin.org_units.territories'))->badge()->placeholder('—')
                    ->state(fn (OrgUnit $record): array => $record->territories->map(fn ($t): string => $t->name())->all()),
                TextColumn::make('memberships_count')->label(__('admin.org_units.members')),
            ])
            ->filters([
                TernaryFilter::make('archived')->label(__('admin.org_units.archived'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query) => $query->whereNull('archived_at'),
                    )->default(false),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [MembersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrgUnits::route('/'),
            'view' => ViewOrgUnit::route('/{record}'),
        ];
    }
}
