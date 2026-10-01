<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectPhase;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Options;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Gantt of a project — phases, their tasks and dependencies — and, without a project chosen,
 * the timeline of all visible projects (ФО §6.8.1).
 */
class ProjectGantt extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 13;

    protected string $view = 'filament.pages.project-gantt';

    public ?int $projectId = null;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->can($user, 'projects.read');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.work');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.views.gantt');
    }

    public function getTitle(): string
    {
        return __('admin.views.gantt');
    }

    public function mount(): void
    {
        $this->projectId = request()->integer('project') ?: null;
    }

    private function actor(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    /**
     * @return array<int, string>
     */
    public function getProjectsProperty(): array
    {
        return app(AuthorizationService::class)->scopeQuery($this->actor(), 'projects.read', Project::query()->whereNull('archived_at'))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Bars as percentages of the chart range.
     *
     * @return array{from: Carbon, to: Carbon, months: list<array{label: string, left: float}>,
     *               rows: list<array{label: string, level: int, left: float, width: float, color: string, note: ?string, url: ?string}>}|null
     */
    public function getChartProperty(): ?array
    {
        $authorization = app(AuthorizationService::class);
        $rows = [];

        if ($this->projectId !== null) {
            $project = Project::query()->find($this->projectId);
            if ($project === null || ! $authorization->can($this->actor(), 'projects.read', $project)) {
                return null;
            }
            $tasks = $authorization->scopeQuery($this->actor(), 'tasks.read', Task::query())->where('project_id', $project->id)->get();
            $statuses = Options::catalog('task_statuses');
            foreach ($project->phases()->with('dependsOn')->get() as $phase) {
                /** @var ProjectPhase $phase */
                $rows[] = ['label' => $phase->name, 'level' => 0, 'start' => $phase->starts_on, 'end' => $phase->due_on,
                    'color' => $phase->status === 'completed' ? 'success' : ($phase->status === 'in_progress' ? 'primary' : 'gray'),
                    'note' => $phase->dependsOn->isNotEmpty() ? __('admin.views.after', ['names' => $phase->dependsOn->pluck('name')->implode(', ')]) : null, 'url' => null];
                foreach ($tasks->where('phase_id', $phase->id) as $task) {
                    $rows[] = $this->taskRow($task, $statuses);
                }
            }
            foreach ($tasks->whereNull('phase_id') as $task) {
                $rows[] = $this->taskRow($task, $statuses);
            }
        } else {
            foreach ($authorization->scopeQuery($this->actor(), 'projects.read', Project::query()->whereNull('archived_at'))->orderBy('starts_on')->get() as $project) {
                $rows[] = ['label' => $project->name, 'level' => 0, 'start' => $project->starts_on, 'end' => $project->due_on,
                    'color' => match ($project->health) {
                        'off_track' => 'danger', 'at_risk' => 'warning', default => 'success'
                    },
                    'note' => __('projects.statuses.'.$project->status), 'url' => null];
            }
        }

        $dated = array_filter($rows, fn (array $r): bool => $r['start'] !== null || $r['end'] !== null);
        if ($dated === []) {
            return ['from' => now(), 'to' => now(), 'months' => [], 'rows' => array_map(fn (array $r): array => [...$r, 'left' => 0.0, 'width' => 0.0], $rows)];
        }
        $from = collect($dated)->map(fn (array $r): Carbon => Carbon::parse($r['start'] ?? $r['end']))->min()->copy()->startOfMonth();
        $to = collect($dated)->map(fn (array $r): Carbon => Carbon::parse($r['end'] ?? $r['start']))->max()->copy()->endOfMonth();
        $span = max(1, $from->diffInDays($to));
        $pos = fn (Carbon $d): float => round(max(0, min(100, $from->diffInDays($d) * 100 / $span)), 2);

        $months = [];
        for ($m = $from->copy(); $m->lte($to); $m->addMonth()) {
            $months[] = ['label' => $m->isoFormat('MMM YYYY'), 'left' => $pos($m)];
        }

        return [
            'from' => $from,
            'to' => $to,
            'months' => $months,
            'rows' => array_map(function (array $r) use ($pos): array {
                $start = $r['start'] !== null ? Carbon::parse($r['start']) : ($r['end'] !== null ? Carbon::parse($r['end']) : null);
                $end = $r['end'] !== null ? Carbon::parse($r['end']) : $start;
                $left = $start !== null ? $pos($start) : 0.0;

                return [...$r, 'left' => $left, 'width' => $end !== null ? max(0.8, $pos($end) - $left) : 0.0];
            }, $rows),
        ];
    }

    /**
     * @param  array<string, string>  $statuses
     * @return array<string, mixed>
     */
    private function taskRow(Task $task, array $statuses): array
    {
        $after = $task->dependsOn()->pluck('title');

        return [
            'label' => $task->title, 'level' => 1, 'start' => $task->created_at, 'end' => $task->due_at,
            'color' => $task->isOverdue() ? 'danger' : ($task->isClosed() ? 'success' : 'info'),
            'note' => ($statuses[$task->status_code] ?? $task->status_code).($after->isNotEmpty() ? ' · '.__('admin.views.after', ['names' => $after->implode(', ')]) : ''),
            'url' => TaskResource::getUrl('view', ['record' => $task]),
        ];
    }
}
