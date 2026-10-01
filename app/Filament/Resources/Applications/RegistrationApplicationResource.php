<?php

declare(strict_types=1);

namespace App\Filament\Resources\Applications;

use App\Domain\Access\Admission\DecideApplication;
use App\Domain\Identity\Enums\ApplicationStatus;
use App\Domain\Identity\Models\RegistrationApplication;
use App\Domain\People\Models\AccountLinkHint;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Applications\Pages\ListRegistrationApplications;
use App\Filament\Support\Options;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Review of membership applications (ФО §6.1): approve with roles or reject with a reason.
 */
class RegistrationApplicationResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = RegistrationApplication::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'applications';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.people');
    }

    public static function getModelLabel(): string
    {
        return __('admin.applications.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.applications.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = RegistrationApplication::query()->where('status', ApplicationStatus::Pending)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canViewAny(): bool
    {
        return static::allows('users.approve');
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
            ->modifyQueryUsing(fn ($query) => $query->with('user.person'))
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('user.person.first_name')->label(__('admin.fields.name'))
                    ->formatStateUsing(fn (RegistrationApplication $record): string => $record->user->person->fullName())
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('user.person', fn ($query) => $query
                        ->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))),
                TextColumn::make('email')->label(__('identity.fields.email'))
                    ->state(fn (RegistrationApplication $record): ?string => $record->user->email ?? $record->user->person->email),
                IconColumn::make('email_verified')->label(__('admin.applications.email_verified'))->boolean()
                    ->state(fn (RegistrationApplication $record): bool => $record->user->hasVerifiedEmail()),
                TextColumn::make('channel')->label(__('admin.applications.channel'))->badge(),
                TextColumn::make('hints')->label(__('admin.applications.hints'))
                    ->state(fn (RegistrationApplication $record): int => AccountLinkHint::query()
                        ->where('new_person_id', $record->user->person_id)->where('status', 'open')->count())
                    ->badge()->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (ApplicationStatus $state): string => $state->label()),
                TextColumn::make('submitted_at')->label(__('admin.applications.submitted_at'))->dateTime()->sortable(),
                TextColumn::make('rejection_reason')->label(__('admin.applications.reason'))->toggleable(isToggledHiddenByDefault: true)->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))
                    ->options(collect(ApplicationStatus::cases())->mapWithKeys(fn (ApplicationStatus $s): array => [$s->value => $s->label()])->all())
                    ->default(ApplicationStatus::Pending->value),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('admin.applications.approve'))
                    ->color('success')
                    ->visible(fn (RegistrationApplication $record): bool => $record->status === ApplicationStatus::Pending)
                    ->schema([
                        Select::make('person_type')->label(__('admin.fields.person_type'))
                            ->options(fn (): array => Options::catalog('person_types'))->default('volunteer')->required(),
                        Select::make('roles')->label(__('admin.fields.roles'))
                            ->options(fn (): array => Options::roles())->multiple(),
                    ])
                    ->action(fn (RegistrationApplication $record, array $data) => static::attempt(
                        fn () => app(DecideApplication::class)->approve(static::actor(), $record, array_values((array) ($data['roles'] ?? [])), (string) $data['person_type']),
                        __('admin.applications.approved'),
                    )),
                Action::make('reject')
                    ->label(__('admin.applications.reject'))
                    ->color('danger')
                    ->visible(fn (RegistrationApplication $record): bool => $record->status === ApplicationStatus::Pending)
                    ->schema([
                        Textarea::make('reason')->label(__('admin.applications.reason'))
                            ->helperText(__('admin.applications.reason_hint'))->required()->maxLength(1000),
                    ])
                    ->action(fn (RegistrationApplication $record, array $data) => static::attempt(
                        fn () => app(DecideApplication::class)->reject(static::actor(), $record, (string) $data['reason']),
                        __('admin.applications.rejected'),
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegistrationApplications::route('/'),
        ];
    }
}
