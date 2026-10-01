<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\CRM\SegmentQuery;
use App\Domain\Events\Actions\EventParticipation;
use App\Domain\Events\Actions\ManageEvents;
use App\Domain\Events\CalendarExport;
use App\Domain\Events\EventVisibility;
use App\Domain\Events\Models\Event;
use App\Domain\Events\Models\EventAttendee;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\GroupMember;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\Options;
use App\Filament\Support\PersonSearch;
use App\Filament\Support\Places;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * The page of an event (ФО §6.7): what, when and where; the answer of the reader; the people invited and going;
 * attendance; the results and the photo report; export to an external calendar. The page asks; the domain decides.
 */
class ViewEvent extends ViewRecord
{
    protected static string $resource = EventResource::class;

    protected string $view = 'filament.events.view';

    private function event(): Event
    {
        $record = $this->getRecord();
        assert($record instanceof Event);

        return $record;
    }

    private function actor(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    private function may(string $code): bool
    {
        return app(EventVisibility::class)->mayManage($this->actor(), $code, $this->event());
    }

    public function getTitle(): string
    {
        return $this->event()->title;
    }

    private function attempt(callable $action, ?string $success = null): bool
    {
        try {
            $action();
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();

            return false;
        }
        $this->getRecord()->refresh();
        if ($success !== null) {
            Notification::make()->title($success)->success()->send();
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $event = $this->event()->loadMissing(['organizer', 'territories', 'groups', 'attachments', 'reminders']);
        $actor = $this->actor();
        $runs = $this->may('events.invite') || $this->may('events.attendance.mark');
        $attendees = EventAttendee::query()->with('person')->where('event_id', $event->id)
            // Whoever does not run the event sees who is coming — not who declined or has not answered.
            ->when(! $runs, fn (Builder $query) => $query->whereIn('rsvp', [EventAttendee::GOING, EventAttendee::INTERESTED]))
            ->orderByRaw("case rsvp when 'going' then 0 when 'interested' then 1 when 'declined' then 3 else 2 end")->orderBy('id')->get();
        $all = EventAttendee::query()->where('event_id', $event->id);
        $visibleGroups = app(GroupAccess::class)->visible($actor)->pluck('id')->flip();

        return [
            'event' => $event,
            'type' => Options::catalog('event_types')[$event->type_code] ?? $event->type_code,
            'audience' => match ($event->visibility) {
                Event::REGIONAL => $event->territories->map(fn ($territory): string => $territory->name())->all(),
                Event::GROUP => $event->groups->filter(fn ($group): bool => $visibleGroups->has($group->id))->pluck('name')->all(),
                default => [],
            },
            'mine' => EventAttendee::query()->where('event_id', $event->id)->where('person_id', $actor->person_id)->first(),
            'attendees' => $attendees,
            'runs' => $runs,
            'mayInvite' => $this->may('events.invite'),
            'mayMark' => $this->may('events.attendance.mark') && $event->hasStarted() && ! $event->isCancelled(),
            'counts' => [
                'going' => (clone $all)->where('rsvp', EventAttendee::GOING)->count(),
                'interested' => (clone $all)->where('rsvp', EventAttendee::INTERESTED)->count(),
                'declined' => (clone $all)->where('rsvp', EventAttendee::DECLINED)->count(),
                'attended' => (clone $all)->where('attended', true)->count(),
            ],
            'googleUrl' => app(CalendarExport::class)->googleUrl($event),
            'series' => $event->series_id !== null
                ? Event::query()->where('series_id', $event->series_id)->where('starts_at', '>', $event->starts_at)->orderBy('starts_at')->limit(5)->get()
                : collect(),
        ];
    }

    protected function getHeaderActions(): array
    {
        $events = app(ManageEvents::class);
        $people = app(EventParticipation::class);
        $open = fn (): bool => ! $this->event()->isCancelled();
        $e = fn (string $key): string => __('events.ui.'.$key);
        $series = fn (): bool => $this->event()->isRecurring();

        return [
            Action::make('respond')->label($e('respond'))->icon('heroicon-o-hand-raised')
                ->visible(fn (): bool => $open() && ! $this->event()->isOver())
                ->fillForm(fn (): array => EventAttendee::query()->where('event_id', $this->event()->id)->where('person_id', $this->actor()->person_id)
                    ->first()?->only(['rsvp', 'rsvp_comment']) ?? ['rsvp' => EventAttendee::GOING])
                ->schema([
                    Select::make('rsvp')->label($e('my_answer'))->required()
                        ->options(collect(EventAttendee::ANSWERS)->mapWithKeys(fn (string $answer): array => [$answer => __('events.rsvp.'.$answer)])->all()),
                    Textarea::make('rsvp_comment')->label($e('comment'))->rows(2)->maxLength(500),
                ])
                ->action(fn (array $data) => $this->attempt(
                    fn () => $people->respond($this->actor(), $this->event(), (string) $data['rsvp'], $data['rsvp_comment'] ?? null), __('admin.saved'),
                )),
            Action::make('invite')->label($e('invite'))->icon('heroicon-o-envelope')
                ->visible(fn (): bool => $open() && $this->may('events.invite'))
                ->schema([
                    Select::make('person_ids')->label(__('social.ui.people'))->multiple()->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search))
                        ->getOptionLabelsUsing(fn (array $values): array => Person::query()->whereKey($values)->get()
                            ->mapWithKeys(fn (Person $person): array => [$person->id => $person->fullName()])->all()),
                ])
                ->action(function (array $data) use ($people): void {
                    $count = 0;
                    if ($this->attempt(function () use ($people, $data, &$count): void {
                        $count = $people->invite($this->actor(), $this->event(), array_map('intval', (array) $data['person_ids']));
                    })) {
                        Notification::make()->title(__('events.ui.invited_count', ['count' => $count]))->success()->send();
                    }
                }),
            ActionGroup::make([
                Action::make('inviteBulk')->label($e('invite_bulk'))->icon('heroicon-o-users')
                    ->visible(fn (): bool => $open() && $this->may('events.invite') && app(AuthorizationService::class)->can($this->actor(), 'events.invite.bulk'))
                    ->modalDescription($e('invite_bulk_hint'))
                    ->schema([
                        Select::make('group_id')->label(__('groups.ui.singular'))
                            ->options(fn (): array => app(GroupAccess::class)->visible($this->actor())->whereNull('archived_at')->orderBy('name')->pluck('name', 'id')->all()),
                        Places::unit('unit_id'),
                        Places::territory(),
                        Select::make('person_types')->label(__('admin.fields.person_type'))->multiple()->options(fn (): array => Options::catalog('person_types')),
                    ])
                    ->action(function (array $data) use ($people): void {
                        $count = 0;
                        if ($this->attempt(function () use ($people, $data, &$count): void {
                            $segments = app(SegmentQuery::class);
                            /** @var Builder<Person> $query */
                            $query = $segments->build($segments->clean($data));
                            if (filled($data['group_id'] ?? null)) {
                                $group = app(GroupAccess::class)->visible($this->actor())->findOrFail($data['group_id']);
                                $query->whereIn('people.id', GroupMember::query()->where('group_id', $group->id)->select('person_id'));
                            }
                            $count = $people->inviteBulk($this->actor(), $this->event(), $query);
                        })) {
                            Notification::make()->title(__('events.ui.invited_count', ['count' => $count]))->success()->send();
                        }
                    }),
                Action::make('attend')->label($e('add_attendee'))->icon('heroicon-o-user-plus')
                    ->visible(fn (): bool => $open() && $this->event()->hasStarted() && $this->may('events.attendance.mark'))
                    ->modalDescription($e('add_attendee_hint'))
                    ->schema([
                        Select::make('person_id')->label(__('groups.ui.person'))->searchable()->required()
                            ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search))
                            ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
                    ])
                    ->action(fn (array $data) => $this->attempt(
                        fn () => $people->markAttendance($this->actor(), $this->event(), Person::query()->findOrFail($data['person_id']), true), __('admin.saved'),
                    )),
                Action::make('results')->label($e('publish_results'))->icon('heroicon-o-document-check')
                    ->visible(fn (): bool => $open() && $this->event()->hasStarted() && $this->may('events.results.publish'))
                    ->fillForm(fn (): array => ['results' => $this->event()->results])
                    ->schema([
                        Textarea::make('results')->label($e('results'))->rows(5)->maxLength(10000),
                        FileUpload::make('photos')->label($e('photos'))->image()->multiple()->maxFiles(20)->maxSize(20480)
                            ->disk('local')->directory('events/incoming')->storeFileNamesIn('photo_names'),
                        FileUpload::make('files')->label($e('files'))->multiple()->maxFiles(10)->maxSize(20480)
                            ->disk('local')->directory('events/incoming')->storeFileNamesIn('file_names'),
                    ])
                    ->action(function (array $data) use ($events): void {
                        $uploaded = fn (string $key, string $names): array => array_map(fn (string $path): array => [
                            'source' => Storage::disk('local')->path($path), 'name' => (string) (((array) ($data[$names] ?? []))[$path] ?? basename($path)),
                        ], array_values((array) ($data[$key] ?? [])));
                        $this->attempt(fn () => $events->publishResults(
                            $this->actor(), $this->event(), $data['results'] ?? null, $uploaded('files', 'file_names'), $uploaded('photos', 'photo_names'),
                        ), __('admin.saved'));
                        Storage::disk('local')->delete([...array_values((array) ($data['files'] ?? [])), ...array_values((array) ($data['photos'] ?? []))]);
                    }),
                Action::make('edit')->label($e('edit'))->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => $open() && $this->may('events.update'))
                    ->fillForm(fn (): array => [
                        ...$this->event()->only(['title', 'type_code', 'starts_at', 'ends_at', 'location', 'latitude', 'longitude', 'description', 'visibility', 'reminder_minutes']),
                        'territory_ids' => $this->event()->territories()->pluck('territories.id')->all(),
                        'group_ids' => $this->event()->groups()->pluck('groups.id')->all(),
                    ])
                    ->schema(fn (): array => [
                        ...EventResource::fields(creating: false),
                        Toggle::make('following')->label($e('this_and_following'))->visible($series),
                    ])
                    ->action(fn (array $data) => $this->attempt(
                        fn () => $events->update($this->actor(), $this->event(), EventResource::payload($data), (bool) ($data['following'] ?? false)), __('admin.saved'),
                    )),
                Action::make('cancel')->label($e('cancel'))->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (): bool => $open() && $this->may('events.update'))
                    ->schema([
                        Textarea::make('reason')->label(__('social.ui.reason'))->rows(2)->required()->maxLength(500),
                        Toggle::make('following')->label($e('this_and_following'))->visible($series),
                    ])
                    ->action(fn (array $data) => $this->attempt(
                        fn () => $events->cancel($this->actor(), $this->event(), (string) $data['reason'], (bool) ($data['following'] ?? false)), $e('cancelled'),
                    )),
            ]),
        ];
    }

    public function markAttendance(int $personId, bool $attended): void
    {
        $this->attempt(fn () => app(EventParticipation::class)->markAttendance($this->actor(), $this->event(), Person::query()->findOrFail($personId), $attended));
    }

    public function uninvite(int $personId): void
    {
        $this->attempt(fn () => app(EventParticipation::class)->uninvite($this->actor(), $this->event(), Person::query()->findOrFail($personId)), __('admin.saved'));
    }
}
