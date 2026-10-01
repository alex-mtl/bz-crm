<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Domain\Access\Actions\AssignRole;
use App\Domain\Access\Actions\RevokeRole;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\UserRole;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Actions\ForcePasswordReset;
use App\Domain\Identity\Actions\ResetTwoFactor;
use App\Domain\Identity\Actions\SetUserActive;
use App\Domain\Identity\Actions\TerminateUserSessions;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Impersonations;
use App\Domain\Identity\Models\Impersonation;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\OrgUnit;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Support\Options;
use App\Http\Impersonation\ImpersonationSession;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Accounts (ФО §6.1): statuses, roles, security actions. Every action goes through a domain action.
 */
class UserResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = User::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.people');
    }

    public static function getModelLabel(): string
    {
        return __('admin.users.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.users.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scoped(parent::getEloquentQuery(), 'users.read');
    }

    public static function canViewAny(): bool
    {
        return static::allows('users.read');
    }

    public static function canView(Model $record): bool
    {
        return static::allows('users.read', $record);
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
     * @return array<int, string> assignment id => role name
     */
    public static function roleNamesOf(User $user): array
    {
        return UserRole::query()->where('user_id', $user->id)->inEffect()->with('role')->get()
            ->mapWithKeys(fn (UserRole $assignment): array => [
                $assignment->id => $assignment->role->name()
                    .static::scopeSuffix($assignment)
                    .($assignment->expires_at !== null ? ' ('.__('admin.users.until', ['date' => $assignment->expires_at->isoFormat('L')]).')' : ''),
            ])->all();
    }

    protected static function scopeSuffix(UserRole $assignment): string
    {
        $scope = ScopeType::fromStored($assignment->scope_type);

        return match ($scope) {
            ScopeType::Organization => '',
            ScopeType::OrgUnit => ' · '.OrgUnit::query()->whereKey($assignment->scope_id)->value('name'),
            ScopeType::Territory => ' · '.Territory::query()->find($assignment->scope_id)?->name(),
            default => ' · '.$scope->label(),
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('person'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('person.last_name')->label(__('admin.fields.name'))
                    ->formatStateUsing(fn (User $record): string => $record->person->fullName())
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('person', fn ($query) => $query
                        ->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")))
                    ->sortable(),
                TextColumn::make('email')->label(__('identity.fields.email'))->searchable(),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (UserStatus $state): string => $state->label())
                    ->color(fn (UserStatus $state): string => match ($state) {
                        UserStatus::Active => 'success', UserStatus::PendingApproval => 'warning', default => 'gray',
                    }),
                TextColumn::make('roles')->label(__('admin.fields.roles'))->badge()
                    ->state(fn (User $record): array => array_values(static::roleNamesOf($record))),
                TextColumn::make('last_login_at')->label(__('admin.users.last_login'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))
                    ->options(collect(UserStatus::cases())->mapWithKeys(fn (UserStatus $s): array => [$s->value => $s->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(static::securityActions()),
            ]);
    }

    /**
     * @return list<Action>
     */
    public static function securityActions(): array
    {
        return [
            Action::make('assignRole')
                ->label(__('admin.users.assign_role'))
                ->icon(Heroicon::OutlinedKey)
                ->visible(fn (): bool => static::allows('roles.assign'))
                ->schema([
                    Select::make('role')->label(__('admin.fields.role'))->options(fn (): array => Options::roles())->required(),
                    ...static::scopeFields(),
                    DateTimePicker::make('expires_at')->label(__('admin.users.expires_at'))->minDate(now()),
                ])
                ->action(fn (User $record, array $data) => static::attempt(
                    fn () => app(AssignRole::class)(
                        static::actor(),
                        $record,
                        Role::query()->where('code', $data['role'])->firstOrFail(),
                        isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
                        scope: ScopeType::from((string) $data['scope']),
                        scopeId: static::scopeIdFrom($data),
                    ),
                    __('admin.users.role_assigned'),
                )),
            Action::make('delegate')
                ->label(__('admin.users.delegate'))
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->visible(fn (): bool => static::allows('delegations.manage'))
                ->schema([
                    Select::make('role')->label(__('admin.fields.role'))->options(fn (): array => Options::roles())->required(),
                    ...static::scopeFields(),
                    DateTimePicker::make('expires_at')->label(__('admin.users.delegation_until'))->minDate(now())->required(),
                    TextInput::make('reason')->label(__('admin.org_units.reason'))->required()->maxLength(500),
                ])
                ->action(fn (User $record, array $data) => static::attempt(
                    fn () => app(AssignRole::class)->delegate(
                        static::actor(), $record, Role::query()->where('code', $data['role'])->firstOrFail(),
                        Carbon::parse($data['expires_at']), (string) $data['reason'],
                        ScopeType::from((string) $data['scope']), static::scopeIdFrom($data),
                    ),
                    __('admin.users.role_assigned'),
                )),
            Action::make('revokeRole')
                ->label(__('admin.users.revoke_role'))
                ->icon(Heroicon::OutlinedNoSymbol)
                ->visible(fn (User $record): bool => static::allows('roles.assign') && static::roleNamesOf($record) !== [])
                ->schema([
                    Select::make('assignment')->label(__('admin.fields.role'))
                        ->options(fn (User $record): array => static::roleNamesOf($record))->required(),
                ])
                ->action(fn (User $record, array $data) => static::attempt(
                    fn () => app(RevokeRole::class)(static::actor(), UserRole::query()->where('user_id', $record->id)->findOrFail($data['assignment'])),
                    __('admin.users.role_revoked'),
                )),
            Action::make('deactivate')
                ->label(__('admin.users.deactivate'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('admin.users.deactivate_warning'))
                ->visible(fn (User $record): bool => $record->status === UserStatus::Active && static::allows('users.deactivate'))
                ->action(fn (User $record) => static::attempt(
                    fn () => app(SetUserActive::class)(static::actor(), $record, false),
                    __('admin.users.deactivated'),
                )),
            Action::make('reactivate')
                ->label(__('admin.users.reactivate'))
                ->icon(Heroicon::OutlinedLockOpen)
                ->requiresConfirmation()
                ->visible(fn (User $record): bool => $record->status === UserStatus::Deactivated && static::allows('users.deactivate'))
                ->action(fn (User $record) => static::attempt(
                    fn () => app(SetUserActive::class)(static::actor(), $record, true),
                    __('admin.users.reactivated'),
                )),
            Action::make('terminateSessions')
                ->label(__('admin.users.terminate_sessions'))
                ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                ->requiresConfirmation()
                ->visible(fn (): bool => static::allows('users.sessions.terminate'))
                ->action(fn (User $record) => static::attempt(
                    fn () => app(TerminateUserSessions::class)(static::actor(), $record),
                    __('admin.users.sessions_terminated'),
                )),
            Action::make('impersonate')
                ->label(__('admin.impersonation.start'))
                ->icon(Heroicon::OutlinedEye)
                ->color('danger')
                ->modalDescription(__('admin.impersonation.warning', ['minutes' => Impersonations::MAX_MINUTES]))
                ->visible(fn (User $record): bool => $record->status === UserStatus::Active && $record->id !== static::actor()->id
                    && static::allows('users.impersonate'))
                ->schema([
                    Textarea::make('reason')->label(__('admin.impersonation.reason'))->required()->maxLength(1000),
                ])
                ->action(function (User $record, array $data) {
                    $impersonation = null;
                    static::attempt(function () use ($record, $data, &$impersonation): void {
                        $impersonation = app(Impersonations::class)->begin(static::actor(), $record, (string) $data['reason']);
                    });
                    if ($impersonation instanceof Impersonation) {
                        app(ImpersonationSession::class)->enter(session()->driver(), $impersonation);

                        return redirect()->to('/admin');
                    }

                    return null;
                }),
            Action::make('forcePasswordReset')
                ->label(__('admin.users.force_password_reset'))
                ->icon(Heroicon::OutlinedKey)
                ->requiresConfirmation()
                ->modalDescription(__('admin.users.force_password_reset_warning'))
                ->visible(fn (User $record): bool => $record->email !== null && static::allows('users.password.reset'))
                ->action(fn (User $record) => static::attempt(
                    fn () => app(ForcePasswordReset::class)(static::actor(), $record),
                    __('admin.users.password_reset_sent'),
                )),
            Action::make('resetTwoFactor')
                ->label(__('admin.users.reset_two_factor'))
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (User $record): bool => $record->hasAppAuthentication() && static::allows('users.2fa.reset'))
                ->action(fn (User $record) => static::attempt(
                    fn () => app(ResetTwoFactor::class)(static::actor(), $record),
                    __('admin.users.two_factor_reset'),
                )),
        ];
    }

    /**
     * Scope of an assignment (ADR-008): organization, a unit subtree, a territory subtree, or the holder's own.
     *
     * @return list<Component>
     */
    public static function scopeFields(): array
    {
        return [
            Select::make('scope')->label(__('admin.users.scope'))->required()->live()->default(ScopeType::Organization->value)
                ->options(collect(ScopeType::cases())->mapWithKeys(fn (ScopeType $s): array => [$s->value => $s->label()])->all()),
            Select::make('scope_unit')->label(__('admin.org_units.singular'))
                ->visible(fn (Get $get): bool => $get('scope') === ScopeType::OrgUnit->value)
                ->options(fn (): array => OrgUnit::query()->active()->orderBy('path')->get()
                    ->mapWithKeys(fn ($u): array => [$u->id => str_repeat('— ', $u->depth).$u->name])->all())->required(),
            Select::make('scope_territory')->label(__('admin.territories.singular'))->searchable()
                ->visible(fn (Get $get): bool => $get('scope') === ScopeType::Territory->value)
                ->getSearchResultsUsing(fn (string $search): array => Territory::query()->search($search)->limit(30)->get()
                    ->mapWithKeys(fn ($t): array => [$t->id => $t->name()])->all())->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function scopeIdFrom(array $data): ?int
    {
        return match ($data['scope'] ?? null) {
            ScopeType::OrgUnit->value => (int) $data['scope_unit'],
            ScopeType::Territory->value => (int) $data['scope_territory'],
            default => null,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
