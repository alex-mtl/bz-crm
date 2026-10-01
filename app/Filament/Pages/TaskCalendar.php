<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskPerson;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calendar of deadlines (ФО §6.8.1) — a month grid of the tasks the viewer may read.
 */
class TaskCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.pages.task-calendar';

    public string $month = '';

    public bool $onlyMine = false;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->can($user, 'tasks.read');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.work');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.views.calendar');
    }

    public function getTitle(): string
    {
        return __('admin.views.calendar').' — '.$this->start()->isoFormat('MMMM YYYY');
    }

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function shift(int $months): void
    {
        $this->month = $this->start()->addMonthsNoOverflow($months)->format('Y-m');
    }

    public function start(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', ($this->month !== '' ? $this->month : now()->format('Y-m')).'-01')->startOfDay();
    }

    /**
     * @return list<array{date: Carbon, inMonth: bool, tasks: Collection<int, Task>}>
     */
    public function getDaysProperty(): array
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);
        $first = $this->start()->startOfWeek();
        $last = $this->start()->endOfMonth()->endOfWeek();

        $query = app(AuthorizationService::class)->scopeQuery($user, 'tasks.read', Task::query())
            ->whereBetween('due_at', [$first, $last->copy()->endOfDay()]);
        if ($this->onlyMine) {
            $query->whereIn('id', TaskPerson::query()->where('person_id', $user->person_id)->select('task_id'));
        }
        $tasks = $query->orderBy('due_at')->get()->groupBy(fn (Task $t): string => $t->due_at?->toDateString() ?? '');

        $days = [];
        for ($day = $first->copy(); $day->lte($last); $day->addDay()) {
            $days[] = ['date' => $day->copy(), 'inMonth' => $day->month === $this->start()->month, 'tasks' => $tasks->get($day->toDateString(), collect())];
        }

        return $days;
    }
}
