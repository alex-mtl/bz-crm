<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Catalogs\Actions\ProposeCatalogItem;
use App\Domain\Catalogs\Actions\ReviewCatalogProposal;
use App\Domain\Catalogs\Actions\SetCatalogItemActive;
use App\Domain\Catalogs\Actions\UpdateCatalogItem;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Discussions;
use App\Domain\People\Models\Person;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Domain\Tasks\Exceptions\TaskRuleViolation;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TimeEntry;
use Database\Seeders\Demo\Personas;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;

/*
 * IMPLEMENTATION-PLAN 2f — tasks (ФО §6.8), task types as a catalog (Д-16), assignees (Д-15).
 */

function demoTask(string $title): Task
{
    return Task::query()->where('title', $title)->firstOrFail();
}

function newDemoTask(string $creator, array $assignees, string $type = 'call'): Task
{
    return app(ManageTasks::class)->create(Personas::user($creator), ['title' => 'Test '.uniqid(), 'type_code' => $type],
        array_map(fn (string $k): int => Personas::user($k)->person_id, $assignees));
}

it('lets the branch A head assign a task to own staff, not to branch B', function () {
    expect(newDemoTask('branch_a_head', ['branch_a_employee_1'])->status_code)->toBe('todo')
        ->and(fn () => newDemoTask('branch_a_head', ['branch_b_employee_1']))->toThrow(AuthorizationException::class);
});

it('has tasks in every status of ФО §6.8.4, overdue computed and blocked with a reason', function () {
    $statuses = Task::query()->pluck('status_code')->unique()->sort()->values()->all();
    $blocked = Task::query()->where('status_code', 'blocked')->sole();

    expect($statuses)->toEqualCanonicalizing(['backlog', 'blocked', 'canceled', 'done', 'draft', 'in_progress', 'in_review', 'on_hold', 'todo'])
        ->and(Task::query()->overdue()->count())->toBeGreaterThan(0)
        ->and($blocked->blocked_reason)->not->toBeNull()
        ->and($blocked->blocked_by)->not->toBeNull()
        ->and(Schema::hasColumn('tasks', 'is_overdue'))->toBeFalse();
});

it('rejects a transition that is not allowed', function () {
    $draft = Task::query()->where('status_code', 'draft')->firstOrFail();

    expect(fn () => app(ManageTasks::class)->changeStatus(Personas::user('branch_a_head'), $draft, 'done'))->toThrow(TaskRuleViolation::class);
});

it('closes an "assignment" only through review, a "call" straight away (Д-16)', function () {
    $tasks = app(ManageTasks::class);
    $assignment = newDemoTask('branch_a_head', ['branch_a_employee_1'], 'assignment');
    $tasks->changeStatus(Personas::user('branch_a_employee_1'), $assignment, 'in_progress');
    expect(fn () => $tasks->changeStatus(Personas::user('branch_a_employee_1'), $assignment, 'done'))->toThrow(TaskRuleViolation::class);

    $call = newDemoTask('branch_a_head', ['branch_a_employee_1'], 'call');
    $tasks->changeStatus(Personas::user('branch_a_employee_1'), $call, 'in_progress');
    $tasks->changeStatus(Personas::user('branch_a_employee_1'), $call, 'done');
    expect($call->fresh()->status_code)->toBe('done');
});

it('keeps old tasks of a deactivated type but refuses the type for new ones', function () {
    $type = CatalogItem::query()->ofCatalog('task_types')->where('code', 'event_preparation')->sole();
    app(SetCatalogItemActive::class)(Personas::user('catalog_admin'), $type, false);

    expect(fn () => newDemoTask('branch_a_head', ['branch_a_employee_1'], 'event_preparation'))->toThrow(TaskRuleViolation::class)
        ->and(Task::query()->where('type_code', 'event_preparation')->count())->toBeGreaterThan(0);
});

it('sends a region head\'s new task type to the queue; after approval everyone can use it (Д-16)', function () {
    $proposal = app(ProposeCatalogItem::class)(Personas::user('chisinau_head'), 'task_types', ['ru' => 'Дежурство у палатки'], 'ru', 'Для агитационных палаток.');
    expect(CatalogItem::query()->ofCatalog('task_types')->where('name_ru', 'Дежурство у палатки')->exists())->toBeFalse();

    $reviewed = app(ReviewCatalogProposal::class)(Personas::user('catalog_admin'), $proposal, true);
    $item = CatalogItem::query()->findOrFail($reviewed->created_item_id);

    expect($item->name_ro)->toBe('Дежурство у палатки')
        ->and($item->unverified_locales)->toEqualCanonicalizing(['ro', 'en'])
        ->and(newDemoTask('branch_a_head', ['branch_a_employee_1'], $item->code)->type_code)->toBe($item->code);

    app(UpdateCatalogItem::class)(Personas::user('catalog_admin'), $item, ['ro' => 'Serviciu la cortul de informare', 'en' => 'Information tent shift']);
    expect($item->fresh()->hasUnverifiedTranslation())->toBeFalse();
});

it('accepts only active users as assignees; a candidate is only a subject (Д-15)', function () {
    $tasks = app(ManageTasks::class);
    $task = newDemoTask('branch_a_head', ['branch_a_employee_1']);
    $candidate = Person::query()->where('person_type', 'candidate')->firstOrFail();
    $applicant = User::query()->where('status', 'pending_approval')->firstOrFail();

    foreach ([$candidate->id, $applicant->person_id, Personas::user('deactivated')->person_id] as $personId) {
        expect(fn () => $tasks->assign(Personas::user('branch_a_head'), $task, [$personId]))->toThrow(TaskRuleViolation::class);
    }
    expect(demoTask('Sunați candidata Lilia Zaharia')->subject_person_id)->toBe($candidate->id);
});

it('keeps the deactivated user\'s open task with a reassignment hint', function () {
    $task = demoTask('Verificarea listelor de semnături');

    expect($task->hasPerson(Personas::user('deactivated')->person_id))->toBeTrue()
        ->and($task->isClosed())->toBeFalse()
        ->and(JournalEntry::query()->where('event_type', 'tasks.reassignment_suggested')->where('subject_id', (string) $task->id)->exists())->toBeTrue()
        ->and(Personas::user('branch_a_head')->notifications()->where('data->kind', 'reassign')->exists())->toBeTrue();
});

it('shows an employee only related tasks, a volunteer only own, a region head both branches', function () {
    $count = fn (string $who) => app(AuthorizationService::class)->scopeQuery(Personas::user($who), 'tasks.read', Task::query())->count();
    $ids = fn (string $who) => app(AuthorizationService::class)->scopeQuery(Personas::user($who), 'tasks.read', Task::query())->pluck('id')->all();

    expect($ids('volunteer'))->toContain(demoTask('Distribuirea pliantelor în blocurile de pe bd. Negruzzi')->id)
        ->not->toContain(demoTask('Zugrăvirea sălii mari')->id)
        ->and($ids('branch_b_employee_3'))->not->toContain(demoTask('Zugrăvirea sălii mari')->id, demoTask('Sunați candidata Lilia Zaharia')->id)
        ->and($count('chisinau_head'))->toBeGreaterThan($count('branch_a_head'));
});

it('has a demo task for every shape of plan 2e', function () {
    $flyers = demoTask('Distribuirea pliantelor în blocurile de pe bd. Negruzzi');
    $room = demoTask('Închirierea sălii');

    expect($flyers->assignees()->count())->toBe(3)
        ->and($flyers->checklist()->count())->toBe(3)
        ->and(Discussions::class)->toBeString()
        ->and(app(Discussions::class)->findFor($flyers)?->messages()->count())->toBe(3)
        ->and($room->depth)->toBe(2)
        ->and(demoTask('Raportul săptămânal al filialei')->recurrence)->toBe(['freq' => 'weekly', 'interval' => 1])
        ->and(TimeEntry::query()->count())->toBeGreaterThan(0)
        ->and(Project::query()->where('template_id', '!=', null)->count())->toBe(1)
        ->and(Project::query()->distinct()->pluck('health')->all())->toContain('on_track', 'at_risk');
});

it('passes the full status cycle with history in the journal', function () {
    $tasks = app(ManageTasks::class);
    $task = newDemoTask('branch_a_head', ['branch_a_employee_1'], 'assignment');
    foreach ([['branch_a_employee_1', 'in_progress'], ['branch_a_employee_1', 'in_review'], ['branch_a_head', 'in_progress', 'Mai trebuie cifrele'], ['branch_a_employee_1', 'in_review'], ['branch_a_head', 'done']] as $step) {
        $tasks->changeStatus(Personas::user($step[0]), $task->fresh(), $step[1], $step[2] ?? null);
    }

    expect(JournalEntry::query()->where('event_type', 'tasks.status.changed')->where('subject_id', (string) $task->id)->count())->toBe(6);
});
