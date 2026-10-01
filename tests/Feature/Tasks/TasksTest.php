<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Catalogs\Actions\ImportReferenceCatalogs;
use App\Domain\Catalogs\Actions\SetCatalogItemActive;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Actions\SetUserActive;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\Message;
use App\Domain\People\Actions\RegisterCandidate;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Domain\Tasks\Events\TaskStatusChanged;
use App\Domain\Tasks\Exceptions\TaskRuleViolation;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Notifications\TaskNotice;
use App\Domain\Tasks\TaskWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\OrgFixture;

beforeEach(function () {
    $this->org = OrgFixture::build();
    app(ImportReferenceCatalogs::class)();
    TaskWorkflow::ensureDefaults();
    $this->tasks = app(ManageTasks::class);
});

function newTask(User $creator, array $assignees = [], string $type = 'call', array $extra = []): Task
{
    return app(ManageTasks::class)->create($creator, ['title' => 'Sarcină', 'type_code' => $type, ...$extra], array_map(fn (User $u) => $u->person_id, $assignees));
}

it('lets a branch head assign own staff, not the other branch (2f)', function () {
    $o = $this->org;

    $task = newTask($o->headA, [$o->a1]);
    expect($task->status_code)->toBe('todo');

    expect(fn () => newTask($o->headA, [$o->b1]))->toThrow(AuthorizationException::class);
});

it('lets an employee assign only themselves', function () {
    $o = $this->org;

    expect(newTask($o->a1, [$o->a1])->status_code)->toBe('todo')
        ->and(fn () => newTask($o->a1, [$o->a2]))->toThrow(AuthorizationException::class);
});

it('accepts only active users as assignees; a candidate can only be the subject (Д-15)', function () {
    $o = $this->org;
    $candidate = app(RegisterCandidate::class)('Lilia', 'Zaharia', null, null, 'test');
    $deactivated = $o->member($o->branchA);
    app(SetUserActive::class)($o->admin, $deactivated, false);

    expect(fn () => $this->tasks->assign($o->headA, newTask($o->headA), [$candidate->id]))->toThrow(TaskRuleViolation::class)
        ->and(fn () => $this->tasks->assign($o->headA, newTask($o->headA), [$deactivated->person_id]))->toThrow(TaskRuleViolation::class);

    $task = newTask($o->headA, [$o->a1], extra: ['subject_person_id' => $candidate->id]);
    expect($task->subject_person_id)->toBe($candidate->id);
});

it('scopes task lists: a region head sees both branches, not Bălți; an employee only related tasks', function () {
    $o = $this->org;
    $a = newTask($o->headA, [$o->a1]);
    $b = newTask($o->headB, [$o->b1]);
    $balti = newTask($o->baltiHead, [$o->balti1]);
    $visible = fn (User $u) => app(AuthorizationService::class)->scopeQuery($u, 'tasks.read', Task::query())->pluck('id')->all();

    expect($visible($o->regionHead))->toContain($a->id, $b->id)->not->toContain($balti->id)
        ->and($visible($o->a1))->toBe([$a->id])
        ->and($visible($o->a2))->toBe([]);
});

it('follows the status rules of ФО §6.8.4 and raises TaskStatusChanged', function () {
    Event::fake([TaskStatusChanged::class]);
    $o = $this->org;
    $task = newTask($o->headA, [$o->a1], 'assignment');

    expect(fn () => $this->tasks->changeStatus($o->a1, $task, 'done'))->toThrow(TaskRuleViolation::class);
    $this->tasks->changeStatus($o->a1, $task, 'in_progress');
    expect(fn () => $this->tasks->changeStatus($o->a1, $task, 'done'))->toThrow(TaskRuleViolation::class, __('tasks.errors.review_required'));
    $this->tasks->changeStatus($o->a1, $task, 'in_review');
    $this->tasks->changeStatus($o->headA, $task, 'done');

    expect($task->fresh()->status_code)->toBe('done')->and($task->fresh()->completed_at)->not->toBeNull();
    Event::assertDispatchedTimes(TaskStatusChanged::class, 4);
    expect(journalCount('tasks.status.changed'))->toBe(4);
});

it('closes a "call" straight from in_progress (no review required)', function () {
    $o = $this->org;
    $task = newTask($o->headA, [$o->a1], 'call');
    $this->tasks->changeStatus($o->a1, $task, 'in_progress');
    $this->tasks->changeStatus($o->a1, $task, 'done');

    expect($task->fresh()->status_code)->toBe('done');
});

it('requires a reason to block and returns to the previous status', function () {
    $o = $this->org;
    $task = newTask($o->headA, [$o->a1]);
    $this->tasks->changeStatus($o->a1, $task, 'in_progress');

    expect(fn () => $this->tasks->changeStatus($o->a1, $task, 'blocked'))->toThrow(TaskRuleViolation::class);
    $this->tasks->changeStatus($o->a1, $task, 'blocked', 'Aștept listele', 'Primăria');
    expect(fn () => $this->tasks->changeStatus($o->a1, $task->fresh(), 'todo'))->toThrow(TaskRuleViolation::class);
    $this->tasks->changeStatus($o->a1, $task->fresh(), 'in_progress');

    expect($task->fresh()->blocked_reason)->toBeNull();
});

it('lets only the creator or a scoped head reopen a done task, with a comment', function () {
    $o = $this->org;
    $task = newTask($o->headA, [$o->a1], 'call');
    $this->tasks->changeStatus($o->a1, $task, 'in_progress');
    $this->tasks->changeStatus($o->a1, $task, 'done');

    expect(fn () => $this->tasks->changeStatus($o->a1, $task->fresh(), 'in_progress', 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->tasks->changeStatus($o->headA, $task->fresh(), 'in_progress'))->toThrow(TaskRuleViolation::class);
    $this->tasks->changeStatus($o->headA, $task->fresh(), 'in_progress', 'De refăcut');
    expect($task->fresh()->status_code)->toBe('in_progress');
});

it('computes overdue instead of storing it', function () {
    $o = $this->org;
    $task = newTask($o->headA, [$o->a1], extra: ['due_at' => now()->subDay()]);

    expect($task->isOverdue())->toBeTrue()
        ->and(Task::query()->overdue()->pluck('id')->all())->toBe([$task->id]);
});

it('does not start before its dependencies are finished', function () {
    $o = $this->org;
    $first = newTask($o->headA, [$o->a1]);
    $second = newTask($o->headA, [$o->a1]);
    $this->tasks->addDependency($o->headA, $second, $first);

    expect(fn () => $this->tasks->changeStatus($o->a1, $second, 'in_progress'))->toThrow(TaskRuleViolation::class)
        ->and(fn () => $this->tasks->addDependency($o->headA, $first, $second))->toThrow(TaskRuleViolation::class);
});

it('refuses a deactivated task type for new tasks but keeps old tasks (Д-16)', function () {
    $o = $this->org;
    $old = newTask($o->headA, [$o->a1], 'meeting');
    app(SetCatalogItemActive::class)($o->admin, CatalogItem::query()->ofCatalog('task_types')->where('code', 'meeting')->sole(), false);

    expect(fn () => newTask($o->headA, [$o->a1], 'meeting'))->toThrow(TaskRuleViolation::class)
        ->and($old->fresh()->type_code)->toBe('meeting');
});

it('keeps subtasks at least three levels deep', function () {
    $o = $this->org;
    $level0 = newTask($o->headA, [$o->a1]);
    $level1 = newTask($o->headA, [$o->a1], extra: ['parent_id' => $level0->id]);
    $level2 = newTask($o->headA, [$o->a1], extra: ['parent_id' => $level1->id]);
    $level3 = newTask($o->headA, [$o->a1], extra: ['parent_id' => $level2->id]);

    expect($level3->depth)->toBe(3);
});

it('keeps a discussion, checklist and time log per task', function () {
    $o = $this->org;
    $task = newTask($o->headA, [$o->a1]);

    $this->tasks->post($o->a1, $task, 'Am început.');
    $item = $this->tasks->addChecklistItem($o->headA, $task, 'Sună la primărie', $o->a1->person_id);
    $this->tasks->toggleChecklistItem($o->a1, $item, true);
    $this->tasks->logTime($o->a1, $task, 45, now());

    expect(Message::query()->count())->toBe(1)
        ->and($item->fresh()->done_at)->not->toBeNull()
        ->and((int) $task->timeEntries()->sum('minutes'))->toBe(45)
        ->and(fn () => $this->tasks->post($o->b1, $task, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->tasks->logTime($o->a2, $task, 10, now()))->toThrow(AuthorizationException::class);
});

it('creates the next occurrence when a recurring task is done', function () {
    $o = $this->org;
    $task = newTask($o->headA, [$o->a1], 'call', ['recurrence' => ['freq' => 'weekly', 'interval' => 1], 'due_at' => now()->addDay()]);
    $this->tasks->changeStatus($o->a1, $task, 'in_progress');
    $this->tasks->changeStatus($o->a1, $task, 'done');

    $next = Task::query()->where('recurrence_of_id', $task->id)->sole();
    expect($next->status_code)->toBe('todo')
        ->and($next->due_at->toDateString())->toBe($task->due_at->addWeek()->toDateString())
        ->and($next->hasPerson($o->a1->person_id))->toBeTrue();
});

it('escalates a long-blocked task to the assignee\'s manager once', function () {
    Notification::fake();
    $o = $this->org;
    $task = newTask($o->regionHead, [$o->a1]);
    $this->tasks->changeStatus($o->a1, $task, 'in_progress');
    $this->tasks->changeStatus($o->a1, $task, 'blocked', 'Aștept', 'Primăria');

    $this->travel(4)->days();
    Artisan::call('tasks:escalate');
    Artisan::call('tasks:escalate');

    Notification::assertSentToTimes($o->headA, TaskNotice::class, 1);
    expect(journalCount('tasks.task.escalated'))->toBe(1);
});

it('suggests reassignment when an assignee is deactivated (Д-15)', function () {
    Notification::fake();
    $o = $this->org;
    $task = newTask($o->regionHead, [$o->a1]);

    app(SetUserActive::class)($o->admin, $o->a1, false);

    expect($task->fresh()->hasPerson($o->a1->person_id))->toBeTrue()
        ->and(journalCount('tasks.reassignment_suggested'))->toBe(1);
    Notification::assertSentTo($o->regionHead, TaskNotice::class);
    Notification::assertSentTo($o->headA, TaskNotice::class);
});

it('lets the creator delete with a mark; the task leaves lists but keeps history', function () {
    $o = $this->org;
    $task = newTask($o->a1, [$o->a1]);

    expect(fn () => $this->tasks->delete($o->a2, $task))->toThrow(AuthorizationException::class);
    $this->tasks->delete($o->a1, $task);

    expect(app(AuthorizationService::class)->scopeQuery($o->a1, 'tasks.read', Task::query())->count())->toBe(0)
        ->and(Task::query()->find($task->id))->not->toBeNull()
        ->and(journalCount('tasks.task.deleted'))->toBe(1);
});

it('applies bulk operations only to tasks in the actor\'s scope', function () {
    $o = $this->org;
    $a = newTask($o->headA, [$o->a1]);
    $b = newTask($o->headB, [$o->b1]);

    $result = $this->tasks->bulk($o->headA, [$a->id, $b->id], ['priority_code' => 'high']);

    expect($result)->toBe(['done' => 1, 'refused' => 1])
        ->and($a->fresh()->priority_code)->toBe('high')
        ->and($b->fresh()->priority_code)->toBe('medium');
});
