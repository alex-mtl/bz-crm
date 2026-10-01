<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Events\CalendarExport;
use App\Domain\Events\Models\CalendarFeed;
use App\Domain\Events\Models\Event;
use App\Domain\Geo\Models\Territory;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\Group;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskPerson;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Places;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * The calendar (ФО §6.7): the person's own — their events and the deadlines of their tasks, — of a group, of a
 * territory. Every view starts from the events the reader may see. External calendars subscribe by iCal feeds.
 */
class EventCalendar extends Page
{
    use ChecksPermissions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'calendar';

    protected string $view = 'filament.pages.event-calendar';

    #[Url]
    public string $month = '';

    #[Url]
    public string $scope = CalendarFeed::PERSONAL;

    #[Url(as: 'group')]
    public ?int $groupId = null;

    #[Url(as: 'territory')]
    public ?int $territoryId = null;

    public bool $withTasks = true;

    public static function canAccess(): bool
    {
        return static::allows('events.read');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.events');
    }

    public static function getNavigationLabel(): string
    {
        return __('events.ui.calendar');
    }

    public function getTitle(): string
    {
        return __('events.ui.calendar').' — '.$this->start()->isoFormat('MMMM YYYY');
    }

    public function mount(): void
    {
        if ($this->month === '') {
            $this->month = now()->format('Y-m');
        }
        if (! in_array($this->scope, [...CalendarFeed::SCOPES, 'all'], true)) {
            $this->scope = CalendarFeed::PERSONAL;
        }
    }

    public function shift(int $months): void
    {
        $this->month = $this->start()->addMonthsNoOverflow($months)->format('Y-m');
    }

    public function start(): Carbon
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $this->month) === 1 ? $this->month : now()->format('Y-m');

        return Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
    }

    /**
     * @return array<int, string>
     */
    public function getGroupsProperty(): array
    {
        return Group::query()->whereKey(app(GroupAccess::class)->groupIdsOf(static::actor()->person_id))->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return list<array{date: Carbon, inMonth: bool, events: Collection<int, Event>, tasks: Collection<int, Task>}>
     */
    public function getDaysProperty(): array
    {
        $actor = static::actor();
        $first = $this->start()->startOfWeek();
        $last = $this->start()->endOfMonth()->endOfWeek();
        $scopeId = match ($this->scope) {
            CalendarFeed::GROUP => $this->groupId, CalendarFeed::TERRITORY => $this->territoryId, default => null,
        };
        // A group or a territory not chosen yet: an empty calendar rather than everything.
        $events = in_array($this->scope, [CalendarFeed::GROUP, CalendarFeed::TERRITORY], true) && $scopeId === null
            ? collect()
            : app(CalendarExport::class)->scoped($actor, $this->scope, $scopeId)
                ->whereBetween('events.starts_at', [$first, $last->copy()->endOfDay()])->orderBy('events.starts_at')->get();
        $eventsByDay = $events->groupBy(fn (Event $event): string => $event->starts_at->toDateString());

        $tasksByDay = collect();
        if ($this->withTasks && $this->scope === CalendarFeed::PERSONAL && static::allows('tasks.read')) {
            $tasksByDay = app(AuthorizationService::class)->scopeQuery($actor, 'tasks.read', Task::query())
                ->whereIn('id', TaskPerson::query()->where('person_id', $actor->person_id)->where('role', TaskPerson::ASSIGNEE)->select('task_id'))
                ->whereNotIn('status_code', Task::CLOSED)->whereBetween('due_at', [$first, $last->copy()->endOfDay()])->orderBy('due_at')->get()
                ->groupBy(fn (Task $task): string => $task->due_at?->toDateString() ?? '');
        }

        $days = [];
        for ($day = $first->copy(); $day->lte($last); $day->addDay()) {
            $key = $day->toDateString();
            $days[] = [
                'date' => $day->copy(), 'inMonth' => $day->month === $this->start()->month,
                'events' => $eventsByDay->get($key, collect()), 'tasks' => $tasksByDay->get($key, collect()),
            ];
        }

        return $days;
    }

    /**
     * @return Collection<int, CalendarFeed>
     */
    public function getFeedsProperty(): Collection
    {
        return CalendarFeed::query()->where('user_id', static::actor()->id)->whereNull('revoked_at')->latest('id')->get();
    }

    public function feedLabel(CalendarFeed $feed): string
    {
        return match ($feed->scope) {
            CalendarFeed::TERRITORY => __('events.ui.scopes.territory').': '.(Territory::query()->find($feed->scope_id)?->name() ?? '—'),
            // The name of a group is shown only while the owner of the feed still sees the group.
            CalendarFeed::GROUP => __('events.ui.scopes.group').': '.(app(GroupAccess::class)->visible(static::actor())->whereKey($feed->scope_id)->value('name') ?? '—'),
            default => __('events.ui.scopes.personal'),
        };
    }

    public function subscribeAction(): Action
    {
        return Action::make('subscribe')
            ->label(__('events.ui.subscribe'))
            ->modalDescription(__('events.ui.subscribe_hint'))
            ->visible(fn (): bool => static::allows('events.calendar.subscribe'))
            ->schema([
                Select::make('scope')->label(__('events.ui.feed_scope'))->required()->live()->default(CalendarFeed::PERSONAL)
                    ->options(collect(CalendarFeed::SCOPES)->mapWithKeys(fn (string $scope): array => [$scope => __('events.ui.scopes.'.$scope)])->all()),
                Select::make('group_id')->label(__('groups.ui.singular'))->required()->options(fn (): array => $this->getGroupsProperty())
                    ->visible(fn (Get $get): bool => $get('scope') === CalendarFeed::GROUP),
                Places::territory()->required()->visible(fn (Get $get): bool => $get('scope') === CalendarFeed::TERRITORY),
            ])
            ->action(function (array $data): void {
                static::attempt(function () use ($data): void {
                    $created = app(CalendarExport::class)->createFeed(static::actor(), (string) $data['scope'], match ($data['scope']) {
                        CalendarFeed::GROUP => (int) $data['group_id'], CalendarFeed::TERRITORY => (int) $data['territory_id'], default => null,
                    });
                    // The address is shown once: only the hash of its token is stored.
                    Notification::make()->title(__('events.ui.feed_created'))->body(route('calendar.feed', ['token' => $created['token']]))
                        ->success()->persistent()->send();
                });
            });
    }

    public function revokeFeed(int $feedId): void
    {
        static::attempt(fn () => app(CalendarExport::class)->revokeFeed(static::actor(), CalendarFeed::query()->findOrFail($feedId)), __('admin.saved'));
    }
}
