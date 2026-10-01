<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Scopes\UnitColumnLocator;
use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Identity\Events\UserDeactivated;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Digests;
use App\Domain\Notifications\NotificationCategories;
use App\Domain\People\Models\Person;
use App\Domain\People\PersonReferences;
use App\Domain\Tasks\Console\EscalateTasksCommand;
use App\Domain\Tasks\Listeners\SuggestReassignment;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskPerson;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class TasksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TaskWorkflow::class);
    }

    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('tasks.task.created', EventCategory::Business),
            new EventType('tasks.task.updated', EventCategory::Business),
            new EventType('tasks.task.people_changed', EventCategory::Business),
            new EventType('tasks.task.deleted', EventCategory::Business, EventSeverity::Notice),
            new EventType('tasks.task.recurred', EventCategory::Business),
            new EventType('tasks.task.escalated', EventCategory::Business, EventSeverity::Notice),
            new EventType('tasks.status.changed', EventCategory::Business),
            new EventType('tasks.checklist.changed', EventCategory::Business),
            new EventType('tasks.dependency.added', EventCategory::Business),
            new EventType('tasks.time.logged', EventCategory::Business),
            new EventType('tasks.reassignment_suggested', EventCategory::Business, EventSeverity::Notice),
            new EventType('tasks.workflow.changed', EventCategory::Admin, EventSeverity::Notice),
        );

        Event::listen(UserDeactivated::class, SuggestReassignment::class);

        if ($this->app->runningInConsole()) {
            $this->commands([EscalateTasksCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('tasks:escalate')->hourly()->withoutOverlapping();
        });

        $this->callAfterResolving(NotificationCategories::class, function (NotificationCategories $categories): void {
            $categories->register('tasks', 'tasks', [NotificationCategories::IN_APP, NotificationCategories::EMAIL]);
        });

        $this->callAfterResolving(Digests::class, function (Digests $digests): void {
            // The reader's own open tasks: overdue, and due in the week after the period.
            $digests->section('tasks', function (User $user, Carbon $from, Carbon $to): ?array {
                $mine = fn (): Builder => Task::query()->notDeleted()->whereNotIn('status_code', Task::CLOSED)
                    ->whereIn('id', TaskPerson::query()->where('person_id', $user->person_id)->where('role', TaskPerson::ASSIGNEE)->select('task_id'));
                $overdue = $mine()->where('due_at', '<', $to)->count();
                $due = $mine()->whereBetween('due_at', [$to, $to->copy()->addDays(7)])->orderBy('due_at')->limit(5)->get();
                $lines = $due->map(fn (Task $task): string => $task->due_at?->isoFormat('D MMM').' — '.$task->title)->all();
                if ($overdue > 0) {
                    array_unshift($lines, __('tasks.digest.overdue', ['count' => $overdue]));
                }

                return $lines === [] ? null : ['title' => __('tasks.digest.title'), 'lines' => $lines, 'url' => '/admin/tasks'];
            });
        });

        // Merging cards (ТЗ §27): tasks about the duplicate become tasks about the kept card.
        $this->callAfterResolving(PersonReferences::class, function (PersonReferences $references): void {
            $references->register('tasks', 'subject_person_id');
            $references->register('tasks', 'creator_person_id');
            $references->register('task_people', 'person_id', ['task_id', 'role']);
            $references->register('task_checklist_items', 'responsible_person_id');
        });

        $this->callAfterResolving(AuthorizationService::class, fn (AuthorizationService $authorization) => $this->configure($authorization));
    }

    private function configure(AuthorizationService $authorization): void
    {
        $authorization->registerLocator(Task::class, new UnitColumnLocator('org_unit_id', 'territory_id'));

        $isCreator = fn (User $user, object $task): bool => $task instanceof Task && $task->exists && $task->creator_person_id === $user->person_id;
        $creatorQuery = fn (User $user, Builder $q) => $q->where($q->getModel()->qualifyColumn('creator_person_id'), $user->person_id);
        $inProject = fn (int $personId, ?int $projectId): bool => $projectId !== null
            && DB::table('project_members')->where('project_id', $projectId)->where('person_id', $personId)->exists();
        $withRole = fn (User $user, Task $task, array $roles): bool => TaskPerson::query()->where('task_id', $task->id)
            ->where('person_id', $user->person_id)->whereIn('role', $roles)->exists();
        $withRoleQuery = fn (User $user, Builder $q, array $roles) => $q->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')
            ->from('task_people')->whereColumn('task_people.task_id', $q->getModel()->qualifyColumn('id'))
            ->where('task_people.person_id', $user->person_id)->whereIn('task_people.role', $roles));

        // tasks.read: creator, assignee, watcher, member of the task's project (Св).
        $authorization->addRelation('tasks.read', AuthorizationService::RELATION_RELATED,
            fn (User $user, object $task): bool => $task instanceof Task && (
                $isCreator($user, $task) || $withRole($user, $task, [TaskPerson::ASSIGNEE, TaskPerson::WATCHER]) || $inProject($user->person_id, $task->project_id)
            ),
            fn (User $user, Builder $q) => $q->where(fn (Builder $w) => $w
                ->where($q->getModel()->qualifyColumn('creator_person_id'), $user->person_id)
                ->orWhere(fn (Builder $p) => $withRoleQuery($user, $p, [TaskPerson::ASSIGNEE, TaskPerson::WATCHER]))
                ->orWhereIn($q->getModel()->qualifyColumn('project_id'), fn (QueryBuilder $sub) => $sub->select('project_id')
                    ->from('project_members')->where('person_id', $user->person_id))));

        // An employee creates tasks for themselves and in projects they take part in.
        $authorization->addRelation('tasks.create', AuthorizationService::RELATION_RELATED,
            fn (User $user, object $task): bool => $task instanceof Task && ($task->project_id === null || $inProject($user->person_id, $task->project_id)));

        // Assigning: an employee only on own tasks, and only themselves ("С — только себе").
        $authorization->addRelation('tasks.assign', AuthorizationService::RELATION_OWN,
            fn (User $user, object $subject): bool => ($subject instanceof Person && $subject->id === $user->person_id) || $isCreator($user, $subject));

        $authorization->addRelation('tasks.status.change', AuthorizationService::RELATION_RELATED,
            fn (User $user, object $task): bool => $task instanceof Task && ($isCreator($user, $task) || $withRole($user, $task, [TaskPerson::ASSIGNEE])));
        $authorization->addRelation('tasks.time.log', AuthorizationService::RELATION_RELATED,
            fn (User $user, object $task): bool => $task instanceof Task && $withRole($user, $task, [TaskPerson::ASSIGNEE]));

        // The creator ("постановщик") updates, reopens and deletes by relation, without a role (catalog §4.2).
        foreach (['tasks.update', 'tasks.reopen', 'tasks.delete'] as $code) {
            $authorization->addRelation($code, AuthorizationService::RELATION_GRANT, $isCreator, $creatorQuery);
        }

        // people.read "Св" for volunteers (catalog §3.2): people they share a task with.
        $sharedTaskIds = fn (int $personId) => DB::table('task_people')->where('person_id', $personId)->select('task_id');
        $authorization->addRelation('people.read', AuthorizationService::RELATION_RELATED,
            fn (User $user, object $person): bool => $person instanceof Person && DB::table('task_people')
                ->whereIn('task_id', $sharedTaskIds($user->person_id))->where('person_id', $person->id)->exists(),
            fn (User $user, Builder $query) => $query->whereIn($query->getModel()->qualifyColumn('id'), fn (QueryBuilder $sub) => $sub
                ->select('person_id')->from('task_people')->whereIn('task_id', $sharedTaskIds($user->person_id))));

        // Deleted tasks are gone from every list; the journal keeps their history.
        $authorization->setQueryScope('tasks.read', fn (User $user, Builder $q) => $q->whereNull($q->getModel()->qualifyColumn('deleted_at')));
        $authorization->addObjectRule('tasks.read', fn (User $user, object $task): bool => ! $task instanceof Task || $task->deleted_at === null);
    }
}
