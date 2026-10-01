<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Catalogs\Actions\ImportReferenceCatalogs;
use App\Domain\Projects\Actions\ManageProjects;
use App\Domain\Projects\Exceptions\ProjectRuleViolation;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\ProjectDashboard;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Domain\Tasks\Exceptions\TaskRuleViolation;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\TaskWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\OrgFixture;

beforeEach(function () {
    $this->org = OrgFixture::build();
    app(ImportReferenceCatalogs::class)();
    TaskWorkflow::ensureDefaults();
    $this->projects = app(ManageProjects::class);
});

it('lets a unit head create a project in own unit only; members see it, others do not', function () {
    $o = $this->org;
    $project = $this->projects->create($o->headA, ['name' => 'Campania Centru'], [$o->a1->person_id]);

    expect(fn () => $this->projects->create($o->headA, ['name' => 'Străin', 'org_unit_id' => $o->branchB->id]))->toThrow(AuthorizationException::class);

    $can = fn ($u) => app(AuthorizationService::class)->can($u, 'projects.read', $project);
    expect($can($o->a1))->toBeTrue()
        ->and($can($o->regionHead))->toBeTrue()
        ->and($can($o->a2))->toBeFalse()
        ->and($can($o->b1))->toBeFalse();
});

it('lets the project manager update it by relation', function () {
    $o = $this->org;
    $project = $this->projects->create($o->headA, ['name' => 'P', 'manager_person_id' => $o->a1->person_id]);

    $this->projects->setStatus($o->a1, $project, 'in_progress', 'at_risk');
    expect($project->fresh()->health)->toBe('at_risk');

    expect(fn () => $this->projects->setStatus($o->a2, $project, 'completed'))->toThrow(AuthorizationException::class);
});

it('creates a project from a template with phases and tasks', function () {
    $o = $this->org;
    $template = $this->projects->saveTemplate($o->admin, 'Agitație', null, [
        'structure' => 'phases', 'strict_phases' => true,
        'phases' => [
            ['name' => 'Pregătire', 'offset_days' => 0, 'duration_days' => 5, 'tasks' => [['title' => 'Materiale', 'type_code' => 'logistics', 'offset_days' => 3]]],
            ['name' => 'Ieșire', 'offset_days' => 5, 'duration_days' => 2, 'tasks' => [['title' => 'Ieșire în teren', 'type_code' => 'field_visit', 'offset_days' => 6]]],
        ],
    ]);

    $project = $this->projects->create($o->headA, ['name' => 'Agitație Centru', 'starts_on' => now()->toDateString()], [$o->a1->person_id], $template);

    expect($project->phases()->count())->toBe(2)
        ->and(Task::query()->where('project_id', $project->id)->count())->toBe(2)
        ->and($project->strict_phases)->toBeTrue();

    $second = $project->phases()->get()[1];
    expect(fn () => $this->projects->setPhaseStatus($o->headA, $second, 'in_progress'))->toThrow(ProjectRuleViolation::class);

    // A task of the second phase cannot start while the first phase is open.
    $task = Task::query()->where('phase_id', $second->id)->sole();
    app(ManageTasks::class)->assign($o->headA, $task, [$o->a1->person_id]);
    app(ManageTasks::class)->changeStatus($o->headA, $task->fresh(), 'todo');
    expect(fn () => app(ManageTasks::class)->changeStatus($o->a1, $task->fresh(), 'in_progress'))->toThrow(TaskRuleViolation::class);
});

it('summarises progress, overdue tasks, workload and budget', function () {
    $o = $this->org;
    $project = $this->projects->create($o->headA, ['name' => 'P'], [$o->a1->person_id]);
    $tasks = app(ManageTasks::class);
    $done = $tasks->create($o->headA, ['title' => 'A', 'type_code' => 'call', 'project_id' => $project->id], [$o->a1->person_id]);
    $tasks->changeStatus($o->a1, $done, 'in_progress');
    $tasks->changeStatus($o->a1, $done, 'done');
    $tasks->create($o->headA, ['title' => 'B', 'type_code' => 'call', 'project_id' => $project->id, 'due_at' => now()->subDay()], [$o->a1->person_id]);
    $this->projects->setBudget($o->headA, $project, 1000, 250);
    $this->projects->addResource($o->headA, $project, ['kind_code' => 'transport', 'description' => 'Microbuz', 'plan_amount' => 2, 'fact_amount' => 1]);

    $summary = app(ProjectDashboard::class)->summary($project);

    expect($summary['progress'])->toBe(50)
        ->and($summary['overdue'])->toBe(1)
        ->and($summary['workload'][0]['open'])->toBe(1)
        ->and($summary['budget']['fact'])->toBe(250.0)
        ->and($summary['budget']['resources'][0]['kind'])->toBe('transport');
});

it('lists only projects in scope or where the user is a member', function () {
    $o = $this->org;
    $a = $this->projects->create($o->headA, ['name' => 'A'], [$o->a1->person_id]);
    $b = $this->projects->create($o->headB, ['name' => 'B']);
    $public = $this->projects->create($o->headB, ['name' => 'Toată organizația', 'visibility' => 'organization']);

    $visible = fn ($u) => app(AuthorizationService::class)->scopeQuery($u, 'projects.read', Project::query())->pluck('id')->all();

    expect($visible($o->a1))->toContain($a->id, $public->id)->not->toContain($b->id)
        ->and($visible($o->regionHead))->toContain($a->id, $b->id, $public->id);
});
