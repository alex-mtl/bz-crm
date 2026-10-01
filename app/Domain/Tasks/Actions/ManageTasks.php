<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Discussions;
use App\Domain\Messaging\Models\Message;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Models\Person;
use App\Domain\Tasks\Events\TaskStatusChanged;
use App\Domain\Tasks\Exceptions\TaskRuleViolation;
use App\Domain\Tasks\Models\ChecklistItem;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskPerson;
use App\Domain\Tasks\Models\TimeEntry;
use App\Domain\Tasks\Notifications\TaskAssigned;
use App\Domain\Tasks\TaskWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tasks (ФО §6.8.2–6.8.4, ТЗ §24–25). Every change is journaled; every status change also raises TaskStatusChanged.
 */
final readonly class ManageTasks
{
    public const int MAX_DEPTH = 4;

    public function __construct(
        private AuthorizationService $authorization,
        private TaskWorkflow $workflow,
        private OrgStructure $org,
        private Discussions $discussions,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array{title: string, type_code: string, description?: string|null, priority_code?: string, project_id?: int|null,
     *               phase_id?: int|null, parent_id?: int|null, org_unit_id?: int|null, territory_id?: int|null,
     *               subject_person_id?: int|null, due_at?: Carbon|string|null, estimate_minutes?: int|null,
     *               recurrence?: array{freq: string, interval: int}|null}  $data
     * @param  list<int>  $assigneeIds
     * @param  list<int>  $watcherIds
     */
    public function create(User $actor, array $data, array $assigneeIds = [], array $watcherIds = []): Task
    {
        $parent = isset($data['parent_id']) ? Task::query()->notDeleted()->findOrFail($data['parent_id']) : null;
        $draft = new Task([
            ...$data,
            'priority_code' => $data['priority_code'] ?? 'medium',
            'creator_person_id' => $actor->person_id,
            'project_id' => $data['project_id'] ?? $parent?->project_id,
            'phase_id' => $data['phase_id'] ?? $parent?->phase_id,
            'org_unit_id' => $data['org_unit_id'] ?? ($parent !== null ? $parent->org_unit_id : null) ?? $this->org->unitOf($actor->person_id)?->id,
            'territory_id' => $data['territory_id'] ?? $parent?->territory_id,
            'depth' => $parent !== null ? $parent->depth + 1 : 0,
        ]);
        $this->authorization->authorize($actor, 'tasks.create', $draft);
        $this->ensureSelectable('task_types', $draft->type_code);
        $this->ensureSelectable('task_priorities', $draft->priority_code);
        if ($draft->depth > self::MAX_DEPTH) {
            throw TaskRuleViolation::because('too_deep');
        }
        if ($draft->subject_person_id !== null && ! $this->authorization->can($actor, 'people.read', Person::query()->findOrFail($draft->subject_person_id))) {
            throw new AuthorizationException(__('access.denied'));
        }

        $task = DB::transaction(function () use ($draft): Task {
            $draft->status_code = 'draft';
            $draft->save();
            $this->discussions->forSubject($draft);
            $this->journal->record('tasks.task.created', $draft, [], $draft->only([
                'title', 'type_code', 'priority_code', 'project_id', 'parent_id', 'org_unit_id', 'subject_person_id', 'due_at',
            ]));

            return $draft;
        });

        if ($assigneeIds !== [] || $watcherIds !== []) {
            $this->assign($actor, $task, $assigneeIds, $watcherIds);
            if ($assigneeIds !== []) {
                $this->changeStatus($actor, $task->fresh() ?? $task, 'todo');
            }
        }

        return $task->fresh() ?? $task;
    }

    /**
     * @param  array<string, mixed>  $data  title, description, priority_code, due_at, estimate_minutes, subject_person_id, recurrence, phase_id
     */
    public function update(User $actor, Task $task, array $data): Task
    {
        $this->authorization->authorize($actor, 'tasks.update', $task);
        $allowed = array_intersect_key($data, array_flip(['title', 'description', 'priority_code', 'due_at', 'estimate_minutes', 'subject_person_id', 'recurrence', 'phase_id', 'territory_id']));
        if (isset($allowed['priority_code'])) {
            $this->ensureSelectable('task_priorities', (string) $allowed['priority_code']);
        }

        return DB::transaction(function () use ($task, $allowed): Task {
            $old = $task->only(array_keys($allowed));
            $task->fill($allowed);
            if ($task->isDirty()) {
                $changed = array_keys($task->getDirty());
                $task->save();
                $this->journal->record('tasks.task.updated', $task, array_intersect_key($old, array_flip($changed)), $task->only($changed));
            }

            return $task;
        });
    }

    /**
     * Assignees and watchers: only people with an active, approved account (Д-15), each within the actor's reach
     * (an employee assigns only themselves). Replaces the lists.
     *
     * @param  list<int>  $assigneeIds
     * @param  list<int>  $watcherIds
     */
    public function assign(User $actor, Task $task, array $assigneeIds, array $watcherIds = []): void
    {
        $this->authorization->authorize($actor, 'tasks.assign', $task);
        $assigneeIds = array_values(array_unique(array_map('intval', $assigneeIds)));
        $watcherIds = array_values(array_diff(array_unique(array_map('intval', $watcherIds)), $assigneeIds));

        foreach ([...$assigneeIds, ...$watcherIds] as $personId) {
            $person = Person::query()->with('user')->find($personId);
            if ($person === null || $person->user === null || ! $person->user->isActive()) {
                throw TaskRuleViolation::because('assignee_not_active_user');
            }
            if (! $this->authorization->can($actor, 'tasks.assign', $person)) {
                throw new AuthorizationException(__('tasks.errors.assignee_out_of_reach'));
            }
        }

        $newAssignees = DB::transaction(function () use ($task, $assigneeIds, $watcherIds): array {
            $before = TaskPerson::query()->where('task_id', $task->id)->get()->groupBy('role')
                ->map(fn ($rows) => $rows->pluck('person_id')->map(fn ($id): int => (int) $id)->sort()->values()->all())->all();
            TaskPerson::query()->where('task_id', $task->id)->delete();
            foreach ([TaskPerson::ASSIGNEE => $assigneeIds, TaskPerson::WATCHER => $watcherIds] as $role => $ids) {
                foreach ($ids as $id) {
                    TaskPerson::query()->create(['task_id' => $task->id, 'person_id' => $id, 'role' => $role]);
                }
            }
            $this->discussions->syncMembers($this->discussions->forSubject($task), [$task->creator_person_id, ...$assigneeIds, ...$watcherIds]);
            sort($assigneeIds);
            sort($watcherIds);
            $this->journal->record('tasks.task.people_changed', $task,
                ['assignees' => $before[TaskPerson::ASSIGNEE] ?? [], 'watchers' => $before[TaskPerson::WATCHER] ?? []],
                ['assignees' => $assigneeIds, 'watchers' => $watcherIds]);

            return array_values(array_diff($assigneeIds, $before[TaskPerson::ASSIGNEE] ?? []));
        });

        foreach (Person::query()->with('user')->whereKey($newAssignees)->get() as $person) {
            if ($person->user !== null && $person->user->person_id !== $task->creator_person_id) {
                $person->user->notify(new TaskAssigned($task));
            }
        }
    }

    /**
     * A status change by the rules of ФО §6.8.4. $note is the reason, comment or resume date the transition requires.
     */
    public function changeStatus(User $actor, Task $task, string $to, ?string $note = null, ?string $blockedBy = null): Task
    {
        $this->authorization->authorize($actor, 'tasks.status.change', $task);
        if (in_array($task->status_code, TaskWorkflow::REOPEN_FROM, true) && $to === 'in_progress') {
            $this->authorization->authorize($actor, 'tasks.reopen', $task);
        }
        if ($to === 'todo' && $task->status_code === 'draft' && ! TaskPerson::query()->where('task_id', $task->id)->where('role', TaskPerson::ASSIGNEE)->exists()) {
            throw TaskRuleViolation::because('needs_assignee');
        }
        $violation = $this->workflow->violation($task, $to);
        if ($violation !== null) {
            throw TaskRuleViolation::because($violation);
        }
        $requires = $this->workflow->requires($task->status_code, $to);
        $note = $note !== null ? trim($note) : null;
        if ($requires !== 'none' && ($note === null || $note === '')) {
            throw TaskRuleViolation::because('requires_'.$requires);
        }
        if ($to === 'blocked' && ($blockedBy === null || trim($blockedBy) === '')) {
            throw TaskRuleViolation::because('requires_blocked_by');
        }

        $from = $task->status_code;
        DB::transaction(function () use ($task, $from, $to, $requires, $note, $blockedBy): void {
            // A new status starts the escalation clock again (ФО §6.8.3).
            $changes = ['status_code' => $to, 'escalated_at' => null];
            if (in_array($to, ['blocked', 'on_hold'], true)) {
                $changes['status_before'] = $from;
            }
            if (in_array($from, ['blocked', 'on_hold'], true)) {
                $changes += ['status_before' => null, 'blocked_reason' => null, 'blocked_by' => null, 'on_hold_until' => null];
            }
            if ($to === 'blocked') {
                $changes += ['blocked_reason' => $note, 'blocked_by' => trim((string) $blockedBy)];
            }
            if ($to === 'on_hold') {
                $changes['on_hold_until'] = Carbon::parse((string) $note)->toDateString();
            }
            if ($to === 'canceled') {
                $changes['cancel_reason'] = $note;
            }
            $changes['completed_at'] = in_array($to, Task::CLOSED, true) ? now() : null;
            $task->update($changes);

            $this->journal->record('tasks.status.changed', $task, ['status' => $from], array_filter([
                'status' => $to,
                $requires === 'none' ? null : $requires => $note,
                'blocked_by' => $to === 'blocked' ? trim((string) $blockedBy) : null,
            ], fn ($v) => $v !== null));
        });

        event(new TaskStatusChanged($task, $from, $to, $actor->id));

        if ($to === 'done' && $task->recurrence !== null) {
            $this->createNextOccurrence($actor, $task);
        }

        return $task;
    }

    public function addChecklistItem(User $actor, Task $task, string $title, ?int $responsiblePersonId = null): ChecklistItem
    {
        $this->authorization->authorize($actor, 'tasks.update', $task);

        return DB::transaction(function () use ($task, $title, $responsiblePersonId): ChecklistItem {
            $item = ChecklistItem::query()->create([
                'task_id' => $task->id, 'title' => trim($title), 'responsible_person_id' => $responsiblePersonId,
                'sort_order' => (int) ChecklistItem::query()->where('task_id', $task->id)->max('sort_order') + 10,
            ]);
            $this->journal->record('tasks.checklist.changed', $task, [], ['item_id' => $item->id, 'added' => $item->title]);

            return $item;
        });
    }

    /**
     * The item's responsible person, the assignees and whoever may update the task tick items off.
     */
    public function toggleChecklistItem(User $actor, ChecklistItem $item, bool $done): void
    {
        $task = Task::query()->findOrFail($item->task_id);
        if ($item->responsible_person_id !== $actor->person_id && ! $task->hasPerson($actor->person_id, TaskPerson::ASSIGNEE)) {
            $this->authorization->authorize($actor, 'tasks.update', $task);
        } else {
            $this->authorization->authorize($actor, 'tasks.read', $task);
        }

        DB::transaction(function () use ($actor, $item, $task, $done): void {
            $item->update(['done_at' => $done ? now() : null, 'done_by_person_id' => $done ? $actor->person_id : null]);
            $this->journal->record('tasks.checklist.changed', $task, [], ['item_id' => $item->id, 'done' => $done]);
        });
    }

    public function addDependency(User $actor, Task $task, Task $dependsOn): void
    {
        $this->authorization->authorize($actor, 'tasks.update', $task);
        $this->authorization->authorize($actor, 'tasks.read', $dependsOn);
        if ($task->id === $dependsOn->id || $this->dependsTransitively($dependsOn, $task->id)) {
            throw TaskRuleViolation::because('dependency_cycle');
        }

        DB::transaction(function () use ($task, $dependsOn): void {
            $task->dependsOn()->syncWithoutDetaching([$dependsOn->id]);
            $this->journal->record('tasks.dependency.added', $task, [], ['depends_on' => $dependsOn->id]);
        });
    }

    public function logTime(User $actor, Task $task, int $minutes, Carbon $spentOn, ?string $note = null): TimeEntry
    {
        $this->authorization->authorize($actor, 'tasks.time.log', $task);
        if ($minutes <= 0 || $minutes > 24 * 60) {
            throw TaskRuleViolation::because('invalid_time');
        }

        return DB::transaction(function () use ($actor, $task, $minutes, $spentOn, $note): TimeEntry {
            $entry = TimeEntry::query()->create([
                'task_id' => $task->id, 'person_id' => $actor->person_id, 'minutes' => $minutes,
                'spent_on' => $spentOn->toDateString(), 'note' => $note,
            ]);
            $this->journal->record('tasks.time.logged', $task, [], ['minutes' => $minutes, 'spent_on' => $spentOn->toDateString()]);

            return $entry;
        });
    }

    public function post(User $actor, Task $task, string $body): Message
    {
        $this->authorization->authorize($actor, 'tasks.read', $task);
        if (trim($body) === '') {
            throw TaskRuleViolation::because('empty_message');
        }

        return $this->discussions->post($this->discussions->forSubject($task), $actor->person_id, $body);
    }

    /**
     * Deletion is a mark (ФО §6.8.2, catalog "удаление с пометкой"): history stays in the journal.
     */
    public function delete(User $actor, Task $task): void
    {
        $this->authorization->authorize($actor, 'tasks.delete', $task);

        DB::transaction(function () use ($actor, $task): void {
            $task->update(['deleted_at' => now(), 'deleted_by_user_id' => $actor->id]);
            $this->journal->record('tasks.task.deleted', $task);
        });
    }

    /**
     * Bulk operations (ФО §6.8.2): each task is checked separately; the result says what was not allowed.
     *
     * @param  list<int>  $taskIds
     * @param  array{status?: string, priority_code?: string, due_at?: string, assignee_ids?: list<int>}  $change
     * @return array{done: int, refused: int}
     */
    public function bulk(User $actor, array $taskIds, array $change): array
    {
        $this->authorization->authorize($actor, 'tasks.bulk');
        $result = ['done' => 0, 'refused' => 0];
        foreach (Task::query()->notDeleted()->whereKey($taskIds)->get() as $task) {
            try {
                if (! $this->authorization->can($actor, 'tasks.bulk', $task)) {
                    throw new AuthorizationException;
                }
                if (isset($change['assignee_ids'])) {
                    $watchers = TaskPerson::query()->where('task_id', $task->id)->where('role', TaskPerson::WATCHER)->pluck('person_id')->map(fn ($id): int => (int) $id)->all();
                    $this->assign($actor, $task, $change['assignee_ids'], $watchers);
                }
                $fields = array_intersect_key($change, array_flip(['priority_code', 'due_at']));
                if ($fields !== []) {
                    $this->update($actor, $task, $fields);
                }
                if (isset($change['status'])) {
                    $this->changeStatus($actor, $task, $change['status']);
                }
                $result['done']++;
            } catch (AuthorizationException|TaskRuleViolation) {
                $result['refused']++;
            }
        }

        return $result;
    }

    /**
     * A recurring task (ФО §6.8.2) spawns its next occurrence when completed.
     */
    private function createNextOccurrence(User $actor, Task $task): void
    {
        $recurrence = $task->recurrence ?? [];
        $interval = max(1, (int) ($recurrence['interval'] ?? 1));
        $due = ($task->due_at ?? now())->copy();
        $due = match ($recurrence['freq'] ?? 'weekly') {
            'daily' => $due->addDays($interval),
            'monthly' => $due->addMonthsNoOverflow($interval),
            default => $due->addWeeks($interval),
        };

        DB::transaction(function () use ($task, $due): void {
            $next = $task->replicate(['status_code', 'completed_at', 'escalated_at', 'blocked_reason', 'blocked_by', 'on_hold_until', 'cancel_reason', 'status_before']);
            $next->fill(['status_code' => 'todo', 'due_at' => $due, 'recurrence_of_id' => $task->recurrence_of_id ?? $task->id]);
            $next->save();
            foreach (TaskPerson::query()->where('task_id', $task->id)->get() as $person) {
                TaskPerson::query()->create(['task_id' => $next->id, 'person_id' => $person->person_id, 'role' => $person->role]);
            }
            $this->discussions->forSubject($next);
            $this->journal->record('tasks.task.recurred', $next, [], ['previous_task_id' => $task->id, 'due_at' => $due->toIso8601String()]);
        });
    }

    private function ensureSelectable(string $catalog, string $code): void
    {
        if (! CatalogItem::query()->ofCatalog($catalog)->selectable()->where('code', $code)->exists()) {
            throw TaskRuleViolation::because($catalog === 'task_types' ? 'inactive_type' : 'inactive_priority');
        }
    }

    private function dependsTransitively(Task $task, int $targetId, int $guard = 0): bool
    {
        if ($guard > 50) {
            return true;
        }
        foreach ($task->dependsOn()->get() as $dependency) {
            if ($dependency->id === $targetId || $this->dependsTransitively($dependency, $targetId, $guard + 1)) {
                return true;
            }
        }

        return false;
    }
}
