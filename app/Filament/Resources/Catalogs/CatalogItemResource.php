<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalogs;

use App\Domain\Catalogs\Actions\MergeCatalogItems;
use App\Domain\Catalogs\Actions\ProposeCatalogItem;
use App\Domain\Catalogs\Actions\SetCatalogItemActive;
use App\Domain\Catalogs\CatalogDefinition;
use App\Domain\Catalogs\CatalogRegistry;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Catalogs\Pages\CreateCatalogItem;
use App\Filament\Resources\Catalogs\Pages\EditCatalogItem;
use App\Filament\Resources\Catalogs\Pages\ListCatalogItems;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Catalogs (Д-16): names in three languages, deactivation instead of deletion, merging of duplicates.
 * Catalog administrators edit; others propose through the review queue.
 */
class CatalogItemResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = CatalogItem::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'catalogs';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.catalogs');
    }

    public static function getModelLabel(): string
    {
        return __('admin.catalogs.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.catalogs.plural');
    }

    public static function canViewAny(): bool
    {
        return static::allows('catalogs.read');
    }

    public static function canCreate(): bool
    {
        return static::allows('catalogs.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return static::allows('catalogs.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function catalogOptions(): array
    {
        return collect(app(CatalogRegistry::class)->all())
            ->mapWithKeys(fn (CatalogDefinition $d): array => [$d->code => $d->label()])->all();
    }

    /**
     * Inputs for the catalog's extra properties (e.g. "requires review" for task types).
     *
     * @return list<Component>
     */
    public static function propertyFields(): array
    {
        $fields = [];
        foreach (app(CatalogRegistry::class)->all() as $definition) {
            foreach ($definition->properties as $key => $type) {
                $visible = fn (Get $get): bool => $get('catalog_code') === $definition->code;
                $label = __('admin.catalogs.properties.'.$key);
                $name = 'properties.'.$definition->code.'.'.$key;
                $fields[] = match ($type) {
                    'bool' => Toggle::make($name)->label($label)->visible($visible),
                    'int' => TextInput::make($name)->label($label)->numeric()->integer()->visible($visible),
                    default => TextInput::make($name)->label($label)->maxLength(255)->visible($visible),
                };
            }
        }

        return $fields;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('catalog_code')->label(__('admin.catalogs.catalog'))
                ->options(fn (): array => static::catalogOptions())
                ->required()->live()->disabledOn('edit'),
            TextInput::make('code')->label(__('admin.catalogs.code'))->maxLength(60)->regex('/^[a-z0-9_]+$/')
                ->helperText(__('admin.catalogs.code_hint'))->disabledOn('edit'),
            Section::make(__('admin.catalogs.names'))->columns(3)->schema([
                TextInput::make('name_ro')->label('ro')->maxLength(150)->requiredWithoutAll(['name_ru', 'name_en']),
                TextInput::make('name_ru')->label('ru')->maxLength(150),
                TextInput::make('name_en')->label('en')->maxLength(150),
            ])->description(__('admin.catalogs.names_hint')),
            Section::make(__('admin.catalogs.properties_title'))->schema(static::propertyFields())
                ->visible(fn (Get $get): bool => ($code = $get('catalog_code')) !== null
                    && app(CatalogRegistry::class)->has($code) && app(CatalogRegistry::class)->get($code)->properties !== []),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('catalog_code')->label(__('admin.catalogs.catalog'))->badge()
                    ->formatStateUsing(fn (string $state): string => static::catalogOptions()[$state] ?? $state),
                TextColumn::make('name')->label(__('admin.fields.name'))
                    ->state(fn (CatalogItem $record): string => $record->name())
                    ->searchable(query: fn ($query, string $search) => $query->where(fn ($query) => $query
                        ->where('name_ro', 'like', "%{$search}%")->orWhere('name_ru', 'like', "%{$search}%")->orWhere('name_en', 'like', "%{$search}%"))),
                TextColumn::make('code')->label(__('admin.catalogs.code'))->fontFamily('mono')->toggleable(),
                IconColumn::make('unverified')->label(__('catalogs.unverified'))->boolean()
                    ->state(fn (CatalogItem $record): bool => $record->hasUnverifiedTranslation())
                    ->trueColor('warning')->falseIcon(null),
                IconColumn::make('is_system')->label(__('admin.catalogs.system'))->boolean(),
                IconColumn::make('is_active')->label(__('admin.catalogs.active'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('catalog_code')->label(__('admin.catalogs.catalog'))->options(fn (): array => static::catalogOptions()),
                TernaryFilter::make('is_active')->label(__('admin.catalogs.active'))->default(true),
            ])
            ->headerActions([
                Action::make('propose')
                    ->label(__('admin.catalogs.propose'))
                    ->icon(Heroicon::OutlinedLightBulb)
                    ->visible(fn (): bool => static::allows('catalogs.propose'))
                    ->schema([
                        Select::make('catalog_code')->label(__('admin.catalogs.catalog'))->options(fn (): array => static::catalogOptions())->required(),
                        TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(150),
                        Textarea::make('justification')->label(__('admin.catalogs.justification'))->required()->maxLength(1000),
                    ])
                    ->action(fn (array $data) => static::attempt(
                        fn () => app(ProposeCatalogItem::class)(
                            static::actor(), (string) $data['catalog_code'], [app()->getLocale() => (string) $data['name']],
                            app()->getLocale(), (string) $data['justification'],
                        ),
                        __('admin.catalogs.proposed'),
                    )),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    Action::make('deactivate')
                        ->label(__('admin.catalogs.deactivate'))
                        ->color('danger')->requiresConfirmation()
                        ->visible(fn (CatalogItem $record): bool => $record->is_active && ! $record->is_system && static::allows('catalogs.manage'))
                        ->action(fn (CatalogItem $record) => static::attempt(
                            fn () => app(SetCatalogItemActive::class)(static::actor(), $record, false), __('admin.saved'))),
                    Action::make('reactivate')
                        ->label(__('admin.catalogs.reactivate'))
                        ->visible(fn (CatalogItem $record): bool => ! $record->is_active && $record->merged_into_id === null && static::allows('catalogs.manage'))
                        ->action(fn (CatalogItem $record) => static::attempt(
                            fn () => app(SetCatalogItemActive::class)(static::actor(), $record, true), __('admin.saved'))),
                    Action::make('merge')
                        ->label(__('admin.catalogs.merge'))
                        ->color('warning')
                        ->visible(fn (CatalogItem $record): bool => $record->is_active && ! $record->is_system && static::allows('catalogs.manage'))
                        ->schema(fn (CatalogItem $record): array => [
                            Select::make('target')->label(__('admin.catalogs.merge_into'))->required()
                                ->options(CatalogItem::query()->ofCatalog($record->catalog_code)->selectable()->whereKeyNot($record->id)->get()
                                    ->mapWithKeys(fn (CatalogItem $i): array => [$i->id => $i->name()])->all()),
                        ])
                        ->modalDescription(__('admin.catalogs.merge_warning'))
                        ->action(fn (CatalogItem $record, array $data) => static::attempt(
                            fn () => app(MergeCatalogItems::class)(static::actor(), $record, CatalogItem::query()->findOrFail($data['target'])),
                            __('admin.catalogs.merged'),
                        )),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCatalogItems::route('/'),
            'create' => CreateCatalogItem::route('/create'),
            'edit' => EditCatalogItem::route('/{record}/edit'),
        ];
    }
}
