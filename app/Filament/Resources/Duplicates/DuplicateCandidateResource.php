<?php

declare(strict_types=1);

namespace App\Filament\Resources\Duplicates;

use App\Domain\CRM\Actions\MergePeople;
use App\Domain\CRM\Duplicates;
use App\Domain\CRM\Models\DuplicateCandidate;
use App\Domain\People\Models\Person;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Duplicates\Pages\ListDuplicateCandidates;
use App\Filament\Resources\People\PersonResource;
use App\Filament\Support\Options;
use App\Filament\Support\Places;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The queue of possible duplicates (ФО §6.9.1). A pair is shown only when both cards are in the reviewer's scope.
 * The system proposes; a person decides — merge (which card stays) or "these are different people".
 */
class DuplicateCandidateResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = DuplicateCandidate::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'duplicates';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.crm');
    }

    public static function getModelLabel(): string
    {
        return __('admin.duplicates.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.duplicates.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = static::getEloquentQuery()->where('status', DuplicateCandidate::OPEN)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('duplicate_candidates.id', app(Duplicates::class)->queueFor(static::actor())->select('duplicate_candidates.id'));
    }

    public static function canViewAny(): bool
    {
        return static::allows('crm.duplicates.review');
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

    public static function describe(Person $person): string
    {
        return implode(' · ', array_filter([
            Options::catalog('person_types')[$person->person_type] ?? $person->person_type,
            $person->phone !== null ? '+'.$person->phone : null,
            $person->email,
            Places::territoryName($person->territory_id),
            $person->user !== null ? __('admin.duplicates.has_account') : null,
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['personA.user', 'personB.user']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('person_a_id')->label(__('admin.duplicates.card', ['n' => 1]))
                    ->formatStateUsing(fn (DuplicateCandidate $record): string => $record->personA->fullName())
                    ->description(fn (DuplicateCandidate $record): string => static::describe($record->personA))
                    ->url(fn (DuplicateCandidate $record): string => PersonResource::getUrl('view', ['record' => $record->person_a_id])),
                TextColumn::make('person_b_id')->label(__('admin.duplicates.card', ['n' => 2]))
                    ->formatStateUsing(fn (DuplicateCandidate $record): string => $record->personB->fullName())
                    ->description(fn (DuplicateCandidate $record): string => static::describe($record->personB))
                    ->url(fn (DuplicateCandidate $record): string => PersonResource::getUrl('view', ['record' => $record->person_b_id])),
                TextColumn::make('reasons')->label(__('admin.duplicates.reasons'))->badge()->color('warning')
                    ->formatStateUsing(fn (string $state): string => __('crm.duplicate_reasons.'.$state)),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.duplicates.statuses.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        DuplicateCandidate::OPEN => 'warning', DuplicateCandidate::MERGED => 'success', default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->default(DuplicateCandidate::OPEN)
                    ->options(collect([DuplicateCandidate::OPEN, DuplicateCandidate::MERGED, DuplicateCandidate::DISMISSED])
                        ->mapWithKeys(fn (string $status): array => [$status => __('admin.duplicates.statuses.'.$status)])->all()),
            ])
            ->recordActions([
                Action::make('merge')->label(__('admin.duplicates.merge'))->icon('heroicon-o-arrows-pointing-in')
                    ->visible(fn (DuplicateCandidate $record): bool => $record->status === DuplicateCandidate::OPEN
                        && static::allows('crm.people.merge', $record->personA) && static::allows('crm.people.merge', $record->personB))
                    ->modalDescription(__('admin.duplicates.merge_hint'))
                    ->schema(fn (DuplicateCandidate $record): array => [
                        Radio::make('keep')->label(__('admin.duplicates.keep'))->required()
                            // The card with an account (or a place in the structure) has to be the one that stays.
                            ->default($record->personB->user !== null && $record->personA->user === null ? 'b' : 'a')
                            ->options([
                                'a' => $record->personA->fullName().' — '.static::describe($record->personA),
                                'b' => $record->personB->fullName().' — '.static::describe($record->personB),
                            ]),
                    ])
                    ->action(fn (DuplicateCandidate $record, array $data) => static::attempt(fn () => app(MergePeople::class)(
                        static::actor(),
                        $data['keep'] === 'b' ? $record->personB : $record->personA,
                        $data['keep'] === 'b' ? $record->personA : $record->personB,
                    ), __('admin.duplicates.merged'))),
                Action::make('dismiss')->label(__('admin.duplicates.dismiss'))->icon('heroicon-o-x-mark')->color('gray')
                    ->visible(fn (DuplicateCandidate $record): bool => $record->status === DuplicateCandidate::OPEN)
                    ->requiresConfirmation()->modalDescription(__('admin.duplicates.dismiss_hint'))
                    ->action(fn (DuplicateCandidate $record) => static::attempt(fn () => app(Duplicates::class)->dismiss(static::actor(), $record), __('admin.saved'))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDuplicateCandidates::route('/')];
    }
}
