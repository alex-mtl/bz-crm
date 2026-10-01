<?php

declare(strict_types=1);

namespace App\Filament\Resources\LinkHints;

use App\Domain\Identity\Actions\DismissLinkHint;
use App\Domain\Identity\Actions\LinkAccounts;
use App\Domain\People\Enums\LinkHintStatus;
use App\Domain\People\Models\AccountLinkHint;
use App\Domain\People\Models\Person;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\LinkHints\Pages\ListAccountLinkHints;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Д-10: "this person may have two accounts" — never linked automatically, only by a person with the right.
 */
class AccountLinkHintResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = AccountLinkHint::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'link-hints';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.people');
    }

    public static function getModelLabel(): string
    {
        return __('admin.link_hints.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.link_hints.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scoped(parent::getEloquentQuery(), 'people.link_hints.read');
    }

    /**
     * Д-10 (phase 2): hints reach the existing person's direct manager too; linking still needs users.link_accounts.
     */
    public static function canViewAny(): bool
    {
        return static::allows('people.link_hints.read') || static::allows('users.link_accounts') || static::scoped(AccountLinkHint::query(), 'people.link_hints.read')->exists();
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
        $describe = fn (Person $person): string => trim($person->fullName().' · '.($person->user->email ?? $person->email ?? '—').' · '.__('admin.link_hints.card').' #'.$person->id);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['newPerson.user', 'existingPerson.user']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('new_person')->label(__('admin.link_hints.new_person'))
                    ->state(fn (AccountLinkHint $record): string => $describe($record->newPerson))->wrap(),
                TextColumn::make('existing_person')->label(__('admin.link_hints.existing_person'))
                    ->state(fn (AccountLinkHint $record): string => $describe($record->existingPerson))->wrap(),
                TextColumn::make('reasons')->label(__('admin.link_hints.reasons'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.link_hints.reason.'.$state)),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (LinkHintStatus $state): string => $state->label()),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))
                    ->options(collect(LinkHintStatus::cases())->mapWithKeys(fn (LinkHintStatus $s): array => [$s->value => $s->label()])->all())
                    ->default(LinkHintStatus::Open->value),
            ])
            ->recordActions([
                Action::make('link')
                    ->label(__('admin.link_hints.link'))
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.link_hints.link_warning'))
                    ->visible(fn (AccountLinkHint $record): bool => $record->status === LinkHintStatus::Open && static::allows('users.link_accounts'))
                    ->action(fn (AccountLinkHint $record) => static::attempt(
                        fn () => app(LinkAccounts::class)(static::actor(), $record),
                        __('admin.link_hints.linked'),
                    )),
                Action::make('dismiss')
                    ->label(__('admin.link_hints.dismiss'))
                    ->color('gray')
                    ->visible(fn (AccountLinkHint $record): bool => $record->status === LinkHintStatus::Open && static::allows('users.link_accounts'))
                    ->action(fn (AccountLinkHint $record) => static::attempt(
                        fn () => app(DismissLinkHint::class)(static::actor(), $record),
                        __('admin.link_hints.dismissed'),
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountLinkHints::route('/'),
        ];
    }
}
