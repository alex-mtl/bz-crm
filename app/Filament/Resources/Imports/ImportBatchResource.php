<?php

declare(strict_types=1);

namespace App\Filament\Resources\Imports;

use App\Domain\Access\AuthorizationService;
use App\Domain\CRM\Imports\PeopleImport;
use App\Domain\CRM\Models\ImportBatch;
use App\Domain\People\Models\Person;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Imports\Pages\ListImportBatches;
use App\Filament\Resources\Imports\Pages\ViewImportBatch;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Imports of people (ТЗ §68): each file is a batch that is validated first (preview, dry run), then imported,
 * and can be rolled back. A person sees their own imports and those of importers within their scope.
 */
class ImportBatchResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = ImportBatch::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 55;

    protected static ?string $slug = 'imports';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.crm');
    }

    public static function getModelLabel(): string
    {
        return __('admin.imports.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.imports.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        $actor = static::actor();
        $creators = app(AuthorizationService::class)->scopeQuery($actor, 'people.import', Person::query())->select('people.id');

        return parent::getEloquentQuery()->where('kind', PeopleImport::KIND)->where(fn (Builder $where) => $where
            ->where('created_by_user_id', $actor->id)
            ->orWhereHas('creator', fn (Builder $user) => $user->whereIn('person_id', $creators)));
    }

    public static function canViewAny(): bool
    {
        return static::allows('people.import');
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof ImportBatch && app(PeopleImport::class)->mayHandle(static::actor(), $record);
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

    public static function statusColor(string $status): string
    {
        return match ($status) {
            ImportBatch::COMPLETED => 'success', ImportBatch::FAILED => 'danger', ImportBatch::ROLLED_BACK => 'gray',
            ImportBatch::IMPORTING => 'info', default => 'warning',
        };
    }

    public static function table(Table $table): Table
    {
        $total = fn (string $key) => fn (ImportBatch $record): int => (int) ($record->totals[$key] ?? 0);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('creator.person'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('original_name')->label(__('admin.imports.file'))->weight('bold')
                    ->description(fn (ImportBatch $record): string => $record->creator->person->fullName().' · '.$record->created_at->isoFormat('LLL')),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.imports.statuses.'.$state))
                    ->color(fn (string $state): string => static::statusColor($state)),
                TextColumn::make('total')->label(__('admin.imports.total'))->state($total('total'))->alignEnd(),
                TextColumn::make('valid')->label(__('admin.imports.valid'))->state($total('valid'))->alignEnd(),
                TextColumn::make('errors')->label(__('admin.imports.errors'))->state($total('errors'))->alignEnd()
                    ->color(fn (ImportBatch $record): ?string => ($record->totals['errors'] ?? 0) > 0 ? 'danger' : null),
                TextColumn::make('duplicates')->label(__('admin.imports.duplicates'))->state($total('duplicates'))->alignEnd(),
                TextColumn::make('imported')->label(__('admin.imports.imported'))->state($total('imported'))->alignEnd(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportBatches::route('/'),
            'view' => ViewImportBatch::route('/{record}'),
        ];
    }
}
