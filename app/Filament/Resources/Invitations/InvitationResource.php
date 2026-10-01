<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invitations;

use App\Domain\Access\Admission\RevokeInvitation;
use App\Domain\Identity\Models\Invitation;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Invitations\Pages\CreateInvitation;
use App\Filament\Resources\Invitations\Pages\ListInvitations;
use App\Filament\Support\Options;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Invitations — the main way in (ФО §6.1). Roles obey "not more than you have" (Д-17).
 */
class InvitationResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Invitation::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.people');
    }

    public static function getModelLabel(): string
    {
        return __('admin.invitations.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.invitations.plural');
    }

    public static function canViewAny(): bool
    {
        return static::allows('users.invite');
    }

    public static function canCreate(): bool
    {
        return static::allows('users.invite');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')->label(__('identity.fields.email'))->email()->required()->maxLength(255),
            TextInput::make('first_name')->label(__('identity.fields.first_name'))->maxLength(100),
            TextInput::make('last_name')->label(__('identity.fields.last_name'))->maxLength(100),
            Select::make('person_type')->label(__('admin.fields.person_type'))
                ->options(fn (): array => Options::catalog('person_types'))
                ->default('employee')->required(),
            Select::make('role_codes')->label(__('admin.fields.roles'))
                ->options(fn (): array => Options::roles())
                ->multiple()
                ->helperText(__('admin.invitations.roles_hint')),
            TextInput::make('valid_days')->label(__('admin.invitations.valid_days'))
                ->numeric()->minValue(1)->maxValue(30)->default(7)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('email')->label(__('identity.fields.email'))->searchable(),
                TextColumn::make('role_codes')->label(__('admin.fields.roles'))->badge()
                    ->formatStateUsing(fn (string $state): string => Options::roles()[$state] ?? $state),
                TextColumn::make('state')->label(__('admin.fields.status'))->badge()
                    ->state(fn (Invitation $record): string => $record->state())
                    ->formatStateUsing(fn (string $state): string => __('admin.invitations.states.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'info', 'accepted' => 'success', default => 'gray',
                    }),
                TextColumn::make('expires_at')->label(__('admin.invitations.expires_at'))->dateTime(),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('admin.invitations.revoke'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Invitation $record): bool => $record->isUsable())
                    ->action(fn (Invitation $record) => static::attempt(
                        fn () => app(RevokeInvitation::class)(static::actor(), $record),
                        __('admin.invitations.revoked'),
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvitations::route('/'),
            'create' => CreateInvitation::route('/create'),
        ];
    }
}
