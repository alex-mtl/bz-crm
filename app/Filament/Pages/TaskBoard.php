<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskPerson;
use App\Domain\Tasks\TaskWorkflow;
use App\Filament\Support\Options;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Kanban (ФО §6.8.1): columns are statuses, cards move only along allowed transitions (ФО §6.8.4).
 * Shows only tasks the viewer may read.
 */
class TaskBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.task-board';

    public ?int $projectId = null;

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
        return __('admin.views.board');
    }

    public function getTitle(): string
    {
        return __('admin.views.board');
    }

    private function actor(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    /**
     * @return Builder<Task>
     */
    public function visibleTasks(): Builder
    {
        $query = app(AuthorizationService::class)->scopeQuery($this->actor(), 'tasks.read', Task::query());
        if ($this->projectId !== null) {
            $query->where('project_id', $this->projectId);
        }
        if ($this->onlyMine) {
            $query->whereIn('id', TaskPerson::query()->where('person_id', $this->actor()->person_id)->select('task_id'));
        }

        return $query;
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
     * @return array<string, array{label: string, tasks: Collection<int, Task>}>
     */
    public function getColumnsProperty(): array
    {
        $tasks = $this->visibleTasks()->with('assignees')->orderBy('due_at')->limit(500)->get()->groupBy('status_code');
        $columns = [];
        foreach (Options::catalog('task_statuses') as $code => $label) {
            $columns[$code] = ['label' => $label, 'tasks' => $tasks->get($code, collect())];
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    public function nextStatuses(Task $task): array
    {
        return app(AuthorizationService::class)->can($this->actor(), 'tasks.status.change', $task)
            ? app(TaskWorkflow::class)->nextStatuses($task)
            : [];
    }

    public function moveAction(): Action
    {
        return Action::make('move')
            ->modalHeading(fn (array $arguments): string => __('admin.tasks.change_status').': '.(Options::catalog('task_statuses')[$arguments['to'] ?? ''] ?? ''))
            ->schema(function (array $arguments): array {
                $task = Task::query()->findOrFail($arguments['task'] ?? 0);
                $requires = app(TaskWorkflow::class)->requires($task->status_code, (string) ($arguments['to'] ?? ''));

                return array_values(array_filter([
                    in_array($requires, ['reason', 'comment'], true) ? Textarea::make('note')->label(__('admin.tasks.requires.'.$requires))->required() : null,
                    $requires === 'date' ? DatePicker::make('note')->label(__('admin.tasks.resume_on'))->required()->minDate(now()) : null,
                    ($arguments['to'] ?? null) === 'blocked' ? TextInput::make('blocked_by')->label(__('admin.tasks.blocked_by'))->required() : null,
                ]));
            })
            ->action(function (array $arguments, array $data): void {
                try {
                    app(ManageTasks::class)->changeStatus($this->actor(), Task::query()->findOrFail($arguments['task'] ?? 0),
                        (string) ($arguments['to'] ?? ''), isset($data['note']) ? (string) $data['note'] : null, $data['blocked_by'] ?? null);
                    Notification::make()->title(__('admin.saved'))->success()->send();
                } catch (AuthorizationException|DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
