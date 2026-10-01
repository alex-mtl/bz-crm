<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuthProviders;

use App\Domain\Identity\Models\AuthProvider;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\AuthProviders\Pages\CreateAuthProvider;
use App\Filament\Resources\AuthProviders\Pages\EditAuthProvider;
use App\Filament\Resources\AuthProviders\Pages\ListAuthProviders;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Registry of external sign-in providers (ФО §6.1): the super admin adds one without code changes.
 * The secret is stored encrypted; the interface only shows whether it is set.
 */
class AuthProviderResource extends Resource
{
    use ChecksPermissions;

    /**
     * Socialite drivers available without extra packages. Adding another means adding its adapter.
     */
    public const array DRIVERS = ['google', 'facebook', 'github', 'gitlab', 'linkedin-openid', 'slack', 'twitter-oauth-2', 'x', 'bitbucket'];

    protected static ?string $model = AuthProvider::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'auth-providers';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.access');
    }

    public static function getModelLabel(): string
    {
        return __('admin.auth_providers.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.auth_providers.plural');
    }

    public static function canViewAny(): bool
    {
        return static::allows('auth.providers.manage');
    }

    public static function canCreate(): bool
    {
        return static::allows('auth.providers.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return static::allows('auth.providers.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label(__('admin.auth_providers.code'))
                ->required()->maxLength(40)->regex('/^[a-z][a-z0-9_-]*$/')
                ->unique(AuthProvider::class, 'code', ignoreRecord: true),
            Select::make('driver')->label(__('admin.auth_providers.driver'))
                ->options(array_combine(self::DRIVERS, self::DRIVERS))->required(),
            TextInput::make('display_name')->label(__('admin.auth_providers.display_name'))->required()->maxLength(60),
            TextInput::make('client_id')->label('Client ID')->maxLength(255),
            TextInput::make('client_secret')->label('Client secret')
                ->password()->autocomplete('new-password')->maxLength(500)
                ->helperText(fn (?AuthProvider $record): string => $record?->hasSecret()
                    ? __('admin.auth_providers.secret_set')
                    : __('admin.auth_providers.secret_missing')),
            TagsInput::make('scopes')->label(__('admin.auth_providers.scopes')),
            TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->numeric()->default(0),
            Toggle::make('is_enabled')->label(__('admin.auth_providers.enabled')),
            Text::make(fn (): string => __('admin.auth_providers.callback_hint', ['url' => url('/auth/{code}/callback')])),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('display_name')->label(__('admin.auth_providers.display_name')),
                TextColumn::make('code')->label(__('admin.auth_providers.code'))->fontFamily('mono'),
                TextColumn::make('driver')->label(__('admin.auth_providers.driver')),
                IconColumn::make('has_secret')->label(__('admin.auth_providers.has_secret'))->boolean()
                    ->state(fn (AuthProvider $record): bool => $record->hasSecret()),
                IconColumn::make('is_enabled')->label(__('admin.auth_providers.enabled'))->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuthProviders::route('/'),
            'create' => CreateAuthProvider::route('/create'),
            'edit' => EditAuthProvider::route('/{record}/edit'),
        ];
    }
}
