<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Scopes\UnitColumnLocator;
use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\ServiceProvider;

final class ProjectsServiceProvider extends ServiceProvider
{
    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('projects.project.created', EventCategory::Business),
            new EventType('projects.project.updated', EventCategory::Business),
            new EventType('projects.project.status_changed', EventCategory::Business),
            new EventType('projects.project.archived', EventCategory::Business),
            new EventType('projects.members.changed', EventCategory::Business),
            new EventType('projects.phase.changed', EventCategory::Business),
            new EventType('projects.budget.changed', EventCategory::Business),
            new EventType('projects.template.saved', EventCategory::Admin),
        );

        $this->callAfterResolving(AuthorizationService::class, function (AuthorizationService $authorization): void {
            $authorization->registerLocator(Project::class, new UnitColumnLocator('org_unit_id', 'territory_id'));

            // projects.read "Св": members of the project, and everyone for an organization-wide project.
            $authorization->addRelation('projects.read', AuthorizationService::RELATION_RELATED,
                fn (User $user, object $project): bool => $project instanceof Project && (
                    $project->visibility === 'organization'
                    || ProjectMember::query()->where('project_id', $project->id)->where('person_id', $user->person_id)->exists()
                ),
                fn (User $user, Builder $q) => $q->where(fn (Builder $w) => $w
                    ->where($q->getModel()->qualifyColumn('visibility'), 'organization')
                    ->orWhereIn($q->getModel()->qualifyColumn('id'), fn (QueryBuilder $sub) => $sub->select('project_id')
                        ->from('project_members')->where('person_id', $user->person_id))));

            // The project manager changes the project, its members and budget by relation (catalog §4.1).
            $isManager = fn (User $user, object $project): bool => $project instanceof Project && $project->exists && $project->manager_person_id === $user->person_id;
            $managerQuery = fn (User $user, Builder $q) => $q->where($q->getModel()->qualifyColumn('manager_person_id'), $user->person_id);
            foreach (['projects.read', 'projects.update', 'projects.members.manage', 'projects.budget.read', 'projects.budget.manage'] as $code) {
                $authorization->addRelation($code, AuthorizationService::RELATION_GRANT, $isManager, $managerQuery);
            }
        });
    }
}
