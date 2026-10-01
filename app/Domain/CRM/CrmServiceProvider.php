<?php

declare(strict_types=1);

namespace App\Domain\CRM;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Scopes\UnitColumnLocator;
use App\Domain\Access\TerritorialAccess;
use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Console\FindDuplicatesCommand;
use App\Domain\CRM\Console\UnfreezeLeadsCommand;
use App\Domain\CRM\Models\Appeal;
use App\Domain\CRM\Models\Lead;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Events\PersonSaved;
use App\Domain\People\Models\Person;
use App\Domain\People\PersonReferences;
use App\Domain\Tasks\Models\TaskPerson;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class CrmServiceProvider extends ServiceProvider
{
    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('crm.interaction.recorded', EventCategory::Business),
            new EventType('crm.relation.added', EventCategory::Data),
            new EventType('crm.relation.removed', EventCategory::Data),
            new EventType('crm.duplicate.dismissed', EventCategory::Data),
            new EventType('crm.people.merged', EventCategory::Data, EventSeverity::Warning),
            new EventType('crm.pipeline.created', EventCategory::Admin),
            new EventType('crm.pipeline.updated', EventCategory::Admin),
            new EventType('crm.lead.created', EventCategory::Business),
            new EventType('crm.lead.stage_changed', EventCategory::Business),
            new EventType('crm.lead.assigned', EventCategory::Business),
            new EventType('crm.lead.unfrozen', EventCategory::Business),
            new EventType('crm.leads.exported', EventCategory::Access, EventSeverity::Notice),
            new EventType('crm.appeal.registered', EventCategory::Business),
            new EventType('crm.appeal.assigned', EventCategory::Business),
            new EventType('crm.appeal.prioritized', EventCategory::Business),
            new EventType('crm.appeal.status_changed', EventCategory::Business),
            new EventType('crm.appeal.task_linked', EventCategory::Business),
            new EventType('crm.appeals.exported', EventCategory::Access, EventSeverity::Notice),
            new EventType('crm.segment.created', EventCategory::Data),
            new EventType('crm.segment.updated', EventCategory::Data),
            new EventType('crm.segment.deleted', EventCategory::Data),
            new EventType('crm.segment.tasks_created', EventCategory::Business, EventSeverity::Notice),
            new EventType('crm.import.uploaded', EventCategory::Data),
            new EventType('crm.import.completed', EventCategory::Data, EventSeverity::Notice),
            new EventType('crm.import.failed', EventCategory::Data, EventSeverity::Warning),
            new EventType('crm.import.rolled_back', EventCategory::Data, EventSeverity::Warning),
        );

        // A new or changed card is checked against the registry at once (ФО §6.9.1).
        Event::listen(PersonSaved::class, fn (PersonSaved $event) => $this->app->make(Duplicates::class)->scan($event->person));
        // Д-22: pipelines take new cards of their types in by themselves.
        Event::listen(PersonSaved::class, function (PersonSaved $event): void {
            if ($event->created) {
                $this->app->make(ManageLeads::class)->enrollAutomatically($event->person);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([UnfreezeLeadsCommand::class, FindDuplicatesCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('crm:unfreeze-leads')->dailyAt('06:00')->withoutOverlapping();
            $schedule->command('crm:find-duplicates')->dailyAt('02:30')->withoutOverlapping();
        });

        $this->callAfterResolving(PersonReferences::class, function (PersonReferences $references): void {
            $references->register('leads', 'person_id');
            $references->register('leads', 'responsible_person_id');
            $references->register('leads', 'created_by_person_id');
            $references->register('lead_stage_history', 'moved_by_person_id');
            $references->register('appeals', 'person_id');
            $references->register('appeals', 'responsible_person_id');
            $references->register('appeals', 'created_by_person_id');
            $references->register('interactions', 'person_id');
            $references->register('interactions', 'author_person_id');
            $references->register('person_relations', 'person_id', ['related_person_id', 'relation_code']);
            $references->register('person_relations', 'related_person_id', ['person_id', 'relation_code']);
        });

        $this->callAfterResolving(AuthorizationService::class, fn (AuthorizationService $authorization) => $this->configure($authorization));
    }

    private function configure(AuthorizationService $authorization): void
    {
        $authorization->registerLocator(Lead::class, new UnitColumnLocator('org_unit_id', 'territory_id'));
        $authorization->registerLocator(Appeal::class, new UnitColumnLocator('org_unit_id', 'territory_id'));

        // "Св": the responsible of a lead or an appeal works with it without any wider scope (catalog §5).
        $responsible = fn (User $user, object $subject): bool => ($subject instanceof Lead || $subject instanceof Appeal)
            && $subject->exists && $subject->responsible_person_id === $user->person_id;
        $responsibleQuery = fn (User $user, Builder $q) => $q->where($q->getModel()->qualifyColumn('responsible_person_id'), $user->person_id);
        foreach (['pipelines.read', 'pipelines.write', 'leads.close', 'appeals.read', 'appeals.close'] as $code) {
            $authorization->addRelation($code, AuthorizationService::RELATION_RELATED, $responsible, $responsibleQuery);
        }

        // The applicant sees their own appeals (catalog §5, "Канд (С)").
        $authorization->addRelation('appeals.read', AuthorizationService::RELATION_OWN,
            fn (User $user, object $appeal): bool => $appeal instanceof Appeal && $appeal->person_id === $user->person_id,
            fn (User $user, Builder $q) => $q->where($q->getModel()->qualifyColumn('person_id'), $user->person_id));

        // An employee registers appeals of their own territories ("Сотр (Т)"), or of their unit when no territory is set.
        $authorization->addRelation('appeals.create', AuthorizationService::RELATION_RELATED, function (User $user, object $appeal): bool {
            if (! $appeal instanceof Appeal) {
                return false;
            }
            if ($appeal->territory_id !== null) {
                $territory = Territory::query()->find($appeal->territory_id);

                return $territory !== null && $this->app->make(TerritorialAccess::class)->covers($user->person_id, $territory);
            }

            return $appeal->org_unit_id !== null && $this->app->make(OrgStructure::class)->unitOf($user->person_id)?->id === $appeal->org_unit_id;
        });

        // An employee records contacts with the people they work with: responsible for their lead or appeal,
        // or assignee of a task about them ("Сотр (Св)").
        $authorization->addRelation('crm.interactions.create', AuthorizationService::RELATION_RELATED,
            fn (User $user, object $person): bool => $person instanceof Person && (
                DB::table('leads')->where('person_id', $person->id)->where('responsible_person_id', $user->person_id)->exists()
                || DB::table('appeals')->where('person_id', $person->id)->where('responsible_person_id', $user->person_id)->exists()
                || DB::table('tasks')->where('subject_person_id', $person->id)->whereNull('deleted_at')
                    ->whereIn('id', DB::table('task_people')->where('person_id', $user->person_id)->where('role', TaskPerson::ASSIGNEE)->select('task_id'))->exists()
            ));
    }
}
