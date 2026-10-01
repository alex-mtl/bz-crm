<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events;

use App\Domain\Events\Actions\ManageEvents;
use App\Domain\Events\EventVisibility;
use App\Domain\Events\Models\Event;
use App\Domain\Events\Models\EventAttendee;
use App\Domain\Geo\Models\Territory;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\Group;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Support\Options;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;

/**
 * Events (ФО §6.7). The list is EventVisibility::visibleTo(): what is not visible is not listed, not counted
 * and not found. Every change goes through ManageEvents / EventParticipation.
 */
class EventResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Event::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'events';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.events');
    }

    public static function getModelLabel(): string
    {
        return __('events.ui.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('events.ui.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('events.id', app(EventVisibility::class)->visibleTo(static::actor())->select('events.id'));
    }

    public static function canViewAny(): bool
    {
        return static::allows('events.read');
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Event && app(EventVisibility::class)->canSee(static::actor(), $record);
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
     * Whoever may hold at least a private event, or runs a group, sees the "create" button; the domain decides the rest.
     */
    public static function mayCreate(): bool
    {
        return static::allows('events.create') || static::managedGroups() !== [];
    }

    /**
     * @return array<int, string> groups the user may hold an event in
     */
    public static function managedGroups(): array
    {
        $actor = static::actor();
        $access = app(GroupAccess::class);
        $mayCreate = static::allows('events.create');

        return Group::query()->whereKey($access->groupIdsOf($actor->person_id))->whereNull('archived_at')->orderBy('name')->get()
            ->filter(fn (Group $group): bool => $mayCreate || $access->canManage($actor, $group))
            ->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    public static function visibilityOptions(): array
    {
        $visibility = app(EventVisibility::class);

        return array_filter([
            Event::PUBLIC => $visibility->mayAddressEveryone(static::actor()) ? __('events.visibility.public') : null,
            Event::REGIONAL => $visibility->mayAddressTerritories(static::actor()) ? __('events.visibility.regional') : null,
            Event::GROUP => static::managedGroups() !== [] ? __('events.visibility.group') : null,
            Event::PRIVATE => static::allows('events.create') ? __('events.visibility.private') : null,
        ]);
    }

    /**
     * @return array<int, string> minutes before the start => label
     */
    public static function reminderOptions(): array
    {
        return collect([10080, 1440, 180, 60, 15])->mapWithKeys(fn (int $minutes): array => [$minutes => static::reminderLabel($minutes)])->all();
    }

    public static function reminderLabel(int $minutes): string
    {
        $key = 'events.ui.reminders.m'.$minutes;

        return Lang::has($key) ? __($key) : __('events.ui.reminder_minutes', ['count' => $minutes]);
    }

    /**
     * @return list<Component>
     */
    public static function fields(bool $creating = true): array
    {
        $e = fn (string $key): string => __('events.ui.'.$key);

        return array_values(array_filter([
            TextInput::make('title')->label($e('title'))->required()->maxLength(255),
            Select::make('type_code')->label($e('type'))->options(fn (): array => Options::catalog('event_types'))->required()->default('meeting'),
            DateTimePicker::make('starts_at')->label($e('starts_at'))->seconds(false)->required(),
            DateTimePicker::make('ends_at')->label($e('ends_at'))->seconds(false)->after('starts_at')->helperText($e('ends_hint')),
            TextInput::make('location')->label($e('location'))->maxLength(255),
            TextInput::make('latitude')->label($e('latitude'))->numeric()->minValue(-90)->maxValue(90),
            TextInput::make('longitude')->label($e('longitude'))->numeric()->minValue(-180)->maxValue(180),
            Textarea::make('description')->label($e('description'))->rows(3)->maxLength(5000),
            Select::make('visibility')->label($e('visibility'))->options(fn (): array => static::visibilityOptions())->required()->live()
                ->default(fn (): ?string => array_key_last(static::visibilityOptions())),
            Select::make('territory_ids')->label(__('social.ui.territories'))->multiple()->searchable()->required()
                ->visible(fn (Get $get): bool => $get('visibility') === Event::REGIONAL)
                ->getSearchResultsUsing(fn (string $search): array => Territory::query()->search($search)->limit(60)->get()
                    ->filter(fn (Territory $territory): bool => app(EventVisibility::class)->coversTerritory(static::actor(), $territory))
                    ->take(30)->mapWithKeys(fn (Territory $territory): array => [$territory->id => $territory->name()])->all())
                ->getOptionLabelsUsing(fn (array $values): array => Territory::query()->whereKey($values)->get()
                    ->mapWithKeys(fn (Territory $territory): array => [$territory->id => $territory->name()])->all()),
            Select::make('group_ids')->label(__('social.ui.groups'))->multiple()->required()
                ->options(fn (): array => static::managedGroups())
                ->visible(fn (Get $get): bool => $get('visibility') === Event::GROUP),
            Select::make('reminder_minutes')->label($e('remind'))->multiple()->options(static::reminderOptions())->default([1440, 60]),
            $creating ? Select::make('frequency')->label($e('repeat'))->live()
                ->options(collect(ManageEvents::FREQUENCIES)->mapWithKeys(fn (string $f): array => [$f => $e('frequencies.'.$f)])->all())
                ->placeholder($e('frequencies.none')) : null,
            $creating ? DatePicker::make('until')->label($e('repeat_until'))->required()
                ->visible(fn (Get $get): bool => filled($get('frequency')))
                ->helperText(__('events.ui.repeat_hint', ['limit' => ManageEvents::MAX_OCCURRENCES])) : null,
        ]));
    }

    /**
     * Turns the form into what ManageEvents takes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function payload(array $data): array
    {
        $payload = array_intersect_key($data, array_flip([
            'title', 'type_code', 'location', 'latitude', 'longitude', 'description', 'visibility', 'territory_ids', 'group_ids', 'reminder_minutes',
        ]));
        $payload['starts_at'] = Carbon::parse($data['starts_at']);
        if (filled($data['ends_at'] ?? null)) {
            $payload['ends_at'] = Carbon::parse($data['ends_at']);
        }
        if (filled($data['frequency'] ?? null)) {
            $payload['recurrence'] = ['frequency' => (string) $data['frequency'], 'until' => $data['until'] ?? null];
        }

        return $payload;
    }

    public static function table(Table $table): Table
    {
        $e = fn (string $key): string => __('events.ui.'.$key);
        $personId = fn (): int => static::actor()->person_id;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('organizer')
                ->withCount(['attendees as going_count' => fn (Builder $q) => $q->where('rsvp', EventAttendee::GOING)]))
            ->defaultSort('starts_at')
            ->recordUrl(fn (Event $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('starts_at')->label($e('starts_at'))->dateTime()->sortable(),
                TextColumn::make('title')->label($e('title'))->weight('bold')->searchable()->wrap()
                    ->description(fn (Event $record): string => implode(' · ', array_filter([
                        Options::catalog('event_types')[$record->type_code] ?? $record->type_code, $record->location,
                        $record->isRecurring() ? $e('recurring') : null,
                    ]))),
                TextColumn::make('visibility')->label($e('visibility'))->badge()->color('gray')
                    ->formatStateUsing(fn (string $state): string => __('events.visibility.'.$state)),
                TextColumn::make('organizer')->label($e('organizer'))->state(fn (Event $record): string => $record->organizer->fullName()),
                TextColumn::make('my_answer')->label($e('my_answer'))->badge()
                    ->state(fn (Event $record): ?string => $record->attendees()->where('person_id', $personId())->value('rsvp'))
                    ->formatStateUsing(fn (?string $state): string => $state !== null ? __('events.rsvp.'.$state) : '')
                    ->color(fn (?string $state): string => match ($state) {
                        EventAttendee::GOING => 'success', EventAttendee::INTERESTED => 'info', default => 'gray',
                    })->placeholder('—'),
                TextColumn::make('going_count')->label($e('going'))->alignEnd(),
                TextColumn::make('cancelled_at')->label(__('admin.fields.status'))->badge()->color('danger')
                    ->formatStateUsing(fn (): string => $e('cancelled'))->placeholder(''),
            ])
            ->filters([
                Filter::make('upcoming')->label($e('upcoming'))->default()->query(fn (Builder $query): Builder => $query->where('ends_at', '>=', now())),
                Filter::make('mine')->label($e('mine'))->query(fn (Builder $query): Builder => $query->where(fn (Builder $mine) => $mine
                    ->where('organizer_person_id', $personId())
                    ->orWhereIn('events.id', EventAttendee::query()->where('person_id', $personId())->select('event_id')))),
                SelectFilter::make('type_code')->label($e('type'))->options(fn (): array => Options::catalog('event_types')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'view' => ViewEvent::route('/{record}'),
        ];
    }
}
