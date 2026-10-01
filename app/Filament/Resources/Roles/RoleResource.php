<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles;

use App\Domain\Access\Models\Role;
use App\Domain\Access\PermissionDefinition;
use App\Domain\Access\PermissionRegistry;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Role constructor (ТЗ §15, Д-17): a role is a named set of allowed / denied permission codes.
 * Deny wins over allow; reserved codes (🔒) are granted only explicitly and are journaled.
 */
class RoleResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Role::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.access');
    }

    public static function getModelLabel(): string
    {
        return __('admin.roles.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.roles.plural');
    }

    public static function canViewAny(): bool
    {
        return static::allows('roles.read');
    }

    public static function canCreate(): bool
    {
        return static::allows('roles.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return static::allows('roles.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * @return array<string, array<string, string>> module => [code => label]
     */
    public static function permissionOptions(): array
    {
        return collect(app(PermissionRegistry::class)->all())
            ->groupBy(fn (PermissionDefinition $d): string => $d->module)
            ->mapWithKeys(fn ($definitions, string $module): array => [
                $module => $definitions
                    ->mapWithKeys(fn (PermissionDefinition $d): array => [
                        $d->code => ($d->reserved ? '🔒 ' : '').$d->label().' · '.$d->code,
                    ])->all(),
            ])->all();
    }

    /**
     * @return list<Component>
     */
    public static function permissionSections(): array
    {
        $sections = [];
        foreach (static::permissionOptions() as $module => $options) {
            $sections[] = Section::make(__('admin.modules.'.$module))->collapsible()->collapsed()->columns(2)->schema([
                CheckboxList::make('allow.'.$module)->label(__('admin.roles.allow'))->options($options)->bulkToggleable(),
                CheckboxList::make('deny.'.$module)->label(__('admin.roles.deny'))->options($options)
                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get, $module): void {
                        if (array_intersect((array) $value, (array) $get('allow.'.$module)) !== []) {
                            $fail(__('admin.roles.allow_and_deny'));
                        }
                    }),
            ]);
        }

        return $sections;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.roles.identity'))->columns(2)->schema([
                TextInput::make('code')->label(__('admin.roles.code'))
                    ->required()->maxLength(50)->regex('/^[a-z][a-z0-9_]*$/')
                    ->unique(Role::class, 'code')
                    ->disabledOn('edit')->dehydrated(fn (string $operation): bool => $operation === 'create'),
                TextInput::make('name_ro')->label(__('admin.roles.name').' (ro)')->maxLength(100)->required(fn (string $operation): bool => $operation === 'create' && app()->getLocale() === 'ro'),
                TextInput::make('name_ru')->label(__('admin.roles.name').' (ru)')->maxLength(100)->required(fn (string $operation): bool => $operation === 'create' && app()->getLocale() === 'ru'),
                TextInput::make('name_en')->label(__('admin.roles.name').' (en)')->maxLength(100)->required(fn (string $operation): bool => $operation === 'create' && app()->getLocale() === 'en'),
            ]),
            ...static::permissionSections(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.roles.name'))->state(fn (Role $record): string => $record->name()),
                TextColumn::make('code')->label(__('admin.roles.code'))->fontFamily('mono'),
                IconColumn::make('is_system')->label(__('admin.roles.system'))->boolean(),
                TextColumn::make('permissions_count')->label(__('admin.roles.permissions_count'))->counts('permissions'),
                IconColumn::make('unverified')->label(__('catalogs.unverified'))->boolean()
                    ->state(fn (Role $record): bool => ($record->unverified_locales ?? []) !== []),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
