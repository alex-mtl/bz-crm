<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Models\Person;
use App\Domain\Projects\Exceptions\ProjectRuleViolation;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectMember;
use App\Domain\Projects\Models\ProjectPhase;
use App\Domain\Projects\Models\ProjectResource;
use App\Domain\Projects\Models\ProjectTemplate;
use App\Domain\Tasks\Actions\ManageTasks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Projects (ФО §6.8.1, ТЗ §23): with or without phases, from templates, members, budget and resources.
 * The project manager changes the project, its members and budget by relation (catalog §4.1).
 */
final readonly class ManageProjects
{
    public function __construct(
        private AuthorizationService $authorization,
        private OrgStructure $org,
        private ManageTasks $tasks,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, manager_person_id?: int|null, org_unit_id?: int|null, territory_id?: int|null,
     *               visibility?: string, structure?: string, strict_phases?: bool, starts_on?: string|null, due_on?: string|null,
     *               budget_plan?: float|null}  $data
     * @param  list<int>  $memberIds
     */
    public function create(User $actor, array $data, array $memberIds = [], ?ProjectTemplate $template = null): Project
    {
        $draft = new Project([
            'visibility' => 'members', 'structure' => $template?->structure['structure'] ?? 'flat',
            'strict_phases' => (bool) ($template?->structure['strict_phases'] ?? false),
            ...$data,
            'manager_person_id' => $data['manager_person_id'] ?? $actor->person_id,
            'org_unit_id' => $data['org_unit_id'] ?? $this->org->unitOf($actor->person_id)?->id,
            'status' => 'not_started',
            'template_id' => $template?->id,
            'created_by_user_id' => $actor->id,
        ]);
        $this->authorization->authorize($actor, 'projects.create', $draft);
        $this->ensureActivePerson($draft->manager_person_id);

        $project = DB::transaction(function () use ($draft, $memberIds, $template): Project {
            $draft->save();
            $this->syncMembers($draft, $memberIds);
            if ($template !== null) {
                $this->applyTemplate($draft, $template);
            }
            $this->journal->record('projects.project.created', $draft, [], [
                ...$draft->only(['name', 'org_unit_id', 'manager_person_id', 'structure', 'visibility']),
                'template_id' => $template?->id,
            ]);

            return $draft;
        });

        // Template tasks are created on the actor's behalf, with the same rights as any task.
        if ($template !== null) {
            $this->createTemplateTasks($template, $project);
        }

        return $project->fresh() ?? $project;
    }

    /**
     * @param  array<string, mixed>  $data  name, description, manager_person_id, visibility, strict_phases, starts_on, due_on, territory_id
     */
    public function update(User $actor, Project $project, array $data): Project
    {
        $this->authorization->authorize($actor, 'projects.update', $project);
        $allowed = array_intersect_key($data, array_flip(['name', 'description', 'manager_person_id', 'visibility', 'strict_phases', 'starts_on', 'due_on', 'territory_id']));
        if (isset($allowed['manager_person_id'])) {
            $this->ensureActivePerson((int) $allowed['manager_person_id']);
        }

        return DB::transaction(function () use ($project, $allowed): Project {
            $old = $project->only(array_keys($allowed));
            $project->fill($allowed);
            if ($project->isDirty()) {
                $changed = array_keys($project->getDirty());
                $project->save();
                if (in_array('manager_person_id', $changed, true)) {
                    $this->syncMembers($project, ProjectMember::query()->where('project_id', $project->id)->where('role', '!=', 'manager')->pluck('person_id')->map(fn ($id): int => (int) $id)->all());
                }
                $this->journal->record('projects.project.updated', $project, array_intersect_key($old, array_flip($changed)), $project->only($changed));
            }

            return $project;
        });
    }

    /**
     * Lifecycle and health are separate axes (ФО §6.8.4); both changes are journaled.
     */
    public function setStatus(User $actor, Project $project, ?string $status, ?string $health = null): void
    {
        $this->authorization->authorize($actor, 'projects.update', $project);
        if (($status !== null && ! in_array($status, Project::STATUSES, true)) || ($health !== null && ! in_array($health, Project::HEALTH, true))) {
            throw ProjectRuleViolation::because('invalid_status');
        }

        DB::transaction(function () use ($project, $status, $health): void {
            $old = $project->only(['status', 'health']);
            $project->update(array_filter(['status' => $status, 'health' => $health]));
            if ($project->wasChanged()) {
                $this->journal->record('projects.project.status_changed', $project, $old, $project->only(['status', 'health']));
            }
        });
    }

    /**
     * @param  list<int>  $memberIds  members besides the manager
     */
    public function setMembers(User $actor, Project $project, array $memberIds): void
    {
        $this->authorization->authorize($actor, 'projects.members.manage', $project);
        foreach ($memberIds as $id) {
            $this->ensureActivePerson($id);
        }

        DB::transaction(function () use ($project, $memberIds): void {
            $old = ProjectMember::query()->where('project_id', $project->id)->pluck('person_id')->sort()->values()->all();
            $this->syncMembers($project, $memberIds);
            $new = ProjectMember::query()->where('project_id', $project->id)->pluck('person_id')->sort()->values()->all();
            $this->journal->record('projects.members.changed', $project, ['members' => $old], ['members' => $new]);
        });
    }

    /**
     * @param  array{name: string, starts_on?: string|null, due_on?: string|null, responsible_person_id?: int|null, budget_plan?: float|null}  $data
     */
    public function addPhase(User $actor, Project $project, array $data): ProjectPhase
    {
        $this->authorization->authorize($actor, 'projects.update', $project);

        return DB::transaction(function () use ($project, $data): ProjectPhase {
            $phase = ProjectPhase::query()->create([
                ...$data,
                'project_id' => $project->id,
                'sort_order' => (int) ProjectPhase::query()->where('project_id', $project->id)->max('sort_order') + 10,
            ]);
            if ($project->structure !== 'phases') {
                $project->update(['structure' => 'phases']);
            }
            $this->journal->record('projects.phase.changed', $project, [], ['phase_id' => $phase->id, 'added' => $phase->name]);

            return $phase;
        });
    }

    public function setPhaseStatus(User $actor, ProjectPhase $phase, string $status): void
    {
        $this->authorization->authorize($actor, 'projects.update', $phase->project);
        if (! in_array($status, ProjectPhase::STATUSES, true)) {
            throw ProjectRuleViolation::because('invalid_status');
        }
        if ($status === 'in_progress' && $phase->project->strict_phases
            && ProjectPhase::query()->where('project_id', $phase->project_id)->where('sort_order', '<', $phase->sort_order)->whereNotIn('status', ['completed', 'canceled'])->exists()) {
            throw ProjectRuleViolation::because('previous_phase_open');
        }
        if ($status === 'in_progress' && $phase->dependsOn()->whereNotIn('status', ['completed', 'canceled'])->exists()) {
            throw ProjectRuleViolation::because('previous_phase_open');
        }

        DB::transaction(function () use ($phase, $status): void {
            $old = $phase->status;
            $phase->update(['status' => $status]);
            $this->journal->record('projects.phase.changed', $phase->project, ['phase_id' => $phase->id, 'status' => $old], ['phase_id' => $phase->id, 'status' => $status]);
        });
    }

    public function addPhaseDependency(User $actor, ProjectPhase $phase, ProjectPhase $dependsOn): void
    {
        $this->authorization->authorize($actor, 'projects.update', $phase->project);
        if ($phase->project_id !== $dependsOn->project_id || $phase->id === $dependsOn->id
            || $dependsOn->dependsOn()->where('project_phases.id', $phase->id)->exists()) {
            throw ProjectRuleViolation::because('dependency_invalid');
        }

        DB::transaction(function () use ($phase, $dependsOn): void {
            $phase->dependsOn()->syncWithoutDetaching([$dependsOn->id]);
            $this->journal->record('projects.phase.changed', $phase->project, [], ['phase_id' => $phase->id, 'depends_on' => $dependsOn->id]);
        });
    }

    public function setBudget(User $actor, Project $project, ?float $plan, ?float $fact): void
    {
        $this->authorization->authorize($actor, 'projects.budget.manage', $project);

        DB::transaction(function () use ($project, $plan, $fact): void {
            $old = $project->only(['budget_plan', 'budget_fact']);
            $project->update(['budget_plan' => $plan, 'budget_fact' => $fact]);
            $this->journal->record('projects.budget.changed', $project, $old, $project->only(['budget_plan', 'budget_fact']));
        });
    }

    /**
     * @param  array{kind_code: string, description: string, phase_id?: int|null, task_id?: int|null, person_id?: int|null,
     *               plan_amount?: float|null, fact_amount?: float|null}  $data
     */
    public function addResource(User $actor, Project $project, array $data): ProjectResource
    {
        $this->authorization->authorize($actor, 'projects.budget.manage', $project);
        if (! CatalogItem::query()->ofCatalog('resource_kinds')->selectable()->where('code', $data['kind_code'])->exists()) {
            throw ProjectRuleViolation::because('invalid_resource_kind');
        }

        return DB::transaction(function () use ($project, $data): ProjectResource {
            $resource = ProjectResource::query()->create([...$data, 'project_id' => $project->id]);
            $this->journal->record('projects.budget.changed', $project, [], ['resource_id' => $resource->id, 'kind' => $resource->kind_code]);

            return $resource;
        });
    }

    public function archive(User $actor, Project $project): void
    {
        $this->authorization->authorize($actor, 'projects.archive', $project);

        DB::transaction(function () use ($project): void {
            $project->update(['archived_at' => now()]);
            $this->journal->record('projects.project.archived', $project);
        });
    }

    /**
     * @param  array<string, mixed>  $structure
     */
    public function saveTemplate(User $actor, string $name, ?string $description, array $structure, ?ProjectTemplate $template = null): ProjectTemplate
    {
        $this->authorization->authorize($actor, 'projects.templates.manage');

        return DB::transaction(function () use ($actor, $name, $description, $structure, $template): ProjectTemplate {
            $template ??= new ProjectTemplate(['created_by_user_id' => $actor->id]);
            $template->fill(['name' => trim($name), 'description' => $description, 'structure' => $structure])->save();
            $this->journal->record('projects.template.saved', $template, [], ['name' => $template->name]);

            return $template;
        });
    }

    /**
     * @param  list<int>  $memberIds
     */
    private function syncMembers(Project $project, array $memberIds): void
    {
        ProjectMember::query()->where('project_id', $project->id)->delete();
        ProjectMember::query()->create(['project_id' => $project->id, 'person_id' => $project->manager_person_id, 'role' => 'manager']);
        foreach (array_unique(array_diff($memberIds, [$project->manager_person_id])) as $id) {
            ProjectMember::query()->create(['project_id' => $project->id, 'person_id' => $id, 'role' => 'member']);
        }
    }

    private function applyTemplate(Project $project, ProjectTemplate $template): void
    {
        $start = $project->starts_on ?? Carbon::today();
        $previous = null;
        foreach ((array) ($template->structure['phases'] ?? []) as $i => $phaseData) {
            $phase = ProjectPhase::query()->create([
                'project_id' => $project->id,
                'name' => (string) $phaseData['name'],
                'sort_order' => ($i + 1) * 10,
                'starts_on' => $start->copy()->addDays((int) ($phaseData['offset_days'] ?? 0)),
                'due_on' => $start->copy()->addDays((int) ($phaseData['offset_days'] ?? 0) + (int) ($phaseData['duration_days'] ?? 7)),
                'responsible_person_id' => $project->manager_person_id,
            ]);
            if ($previous !== null && ($template->structure['strict_phases'] ?? false)) {
                $phase->dependsOn()->attach($previous->id);
            }
            $previous = $phase;
        }
    }

    private function createTemplateTasks(ProjectTemplate $template, Project $project): void
    {
        $actor = User::query()->where('person_id', $project->manager_person_id)->first();
        if ($actor === null) {
            return;
        }
        $start = $project->starts_on ?? Carbon::today();
        $phases = ProjectPhase::query()->where('project_id', $project->id)->orderBy('sort_order')->get()->values();
        foreach ((array) ($template->structure['phases'] ?? []) as $i => $phaseData) {
            foreach ((array) ($phaseData['tasks'] ?? []) as $taskData) {
                $this->tasks->create($actor, [
                    'title' => (string) $taskData['title'],
                    'type_code' => (string) ($taskData['type_code'] ?? 'assignment'),
                    'project_id' => $project->id,
                    'phase_id' => $phases[$i]->id ?? null,
                    'org_unit_id' => $project->org_unit_id,
                    'due_at' => $start->copy()->addDays((int) ($taskData['offset_days'] ?? 7))->setTime(18, 0),
                ]);
            }
        }
        foreach ((array) ($template->structure['tasks'] ?? []) as $taskData) {
            $this->tasks->create($actor, [
                'title' => (string) $taskData['title'],
                'type_code' => (string) ($taskData['type_code'] ?? 'assignment'),
                'project_id' => $project->id,
                'org_unit_id' => $project->org_unit_id,
                'due_at' => $start->copy()->addDays((int) ($taskData['offset_days'] ?? 7))->setTime(18, 0),
            ]);
        }
    }

    private function ensureActivePerson(int $personId): void
    {
        $person = Person::query()->with('user')->find($personId);
        if ($person === null || $person->user === null || ! $person->user->isActive()) {
            throw ProjectRuleViolation::because('member_not_active_user');
        }
    }
}
