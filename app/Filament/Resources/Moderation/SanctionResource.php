<?php

declare(strict_types=1);

namespace App\Filament\Resources\Moderation;

use App\Domain\People\Models\Person;
use App\Domain\Social\Models\ModerationAction;
use App\Domain\Social\Moderation;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Moderation\Pages\ListSanctions;
use App\Filament\Support\PersonSearch;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What the moderators did to the people of the viewer's scope (ФО §6.4.4): hidings, restorations, warnings, mutes.
 * A mute still running can be lifted from here.
 */
class SanctionResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = ModerationAction::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'moderation-actions';

    public static function getModelLabel(): string
    {
        return __('social.moderation.sanction');
    }

    public static function getPluralModelLabel(): string
    {
        return __('social.moderation.sanctions');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('moderation_actions.id', app(Moderation::class)->actionsFor(static::actor())->select('moderation_actions.id'));
    }

    public static function canViewAny(): bool
    {
        return static::allows('moderation.queue.read');
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
     * A person picker for a new warning or mute: people.read decides who can be found, Moderation — who can be punished.
     */
    public static function personField(): Select
    {
        return Select::make('person_id')->label(__('groups.ui.person'))->searchable()->required()
            ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, null, true))
            ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value));
    }

    public static function table(Table $table): Table
    {
        $m = fn (string $key): string => __('social.moderation.'.$key);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['person', 'moderator.person']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
                TextColumn::make('action')->label($m('action'))->badge()
                    ->formatStateUsing(fn (string $state): string => $m('actions.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        ModerationAction::MUTE, ModerationAction::HIDE => 'danger', ModerationAction::WARN => 'warning', default => 'gray',
                    }),
                TextColumn::make('person')->label(__('groups.ui.person'))->state(fn (ModerationAction $record): ?string => $record->person?->fullName()),
                TextColumn::make('reason')->label(__('social.ui.reason'))->wrap()->placeholder('—'),
                TextColumn::make('expires_at')->label($m('mute_until'))->dateTime()->placeholder('—')
                    ->description(fn (ModerationAction $record): ?string => match (true) {
                        $record->action !== ModerationAction::MUTE => null,
                        $record->isActiveMute() => $m('mute_active'),
                        default => $m('mute_over'),
                    }),
                TextColumn::make('moderator')->label($m('moderator'))->state(fn (ModerationAction $record): ?string => $record->moderator?->person->fullName()),
            ])
            ->filters([
                SelectFilter::make('action')->label($m('action'))->options(collect([
                    ModerationAction::WARN, ModerationAction::MUTE, ModerationAction::UNMUTE, ModerationAction::HIDE, ModerationAction::RESTORE,
                ])->mapWithKeys(fn (string $action): array => [$action => $m('actions.'.$action)])->all()),
            ])
            ->recordActions([
                Action::make('unmute')->label($m('unmute'))->icon('heroicon-o-speaker-wave')
                    ->visible(fn (ModerationAction $record): bool => $record->isActiveMute() && $record->person !== null && static::allows('moderation.mute', $record->person))
                    ->requiresConfirmation()
                    ->action(fn (ModerationAction $record) => static::attempt(
                        fn () => app(Moderation::class)->unmute(static::actor(), $record->person ?? new Person),
                        $m('unmuted'),
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSanctions::route('/')];
    }
}
