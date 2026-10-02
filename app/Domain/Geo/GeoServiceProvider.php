<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Scopes\PersonLocator;
use App\Domain\Access\Scopes\TerritoryColumnLocator;
use App\Domain\Access\Scopes\UnitColumnLocator;
use App\Domain\Access\TerritorialAccess;
use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Events\Models\Event as OrganizationEvent;
use App\Domain\Geo\Console\ImportTerritoriesCommand;
use App\Domain\Geo\Console\PurgeLocationsCommand;
use App\Domain\Geo\Models\GeoZone;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\LocationShare;
use App\Domain\Geo\Models\Territory;
use App\Domain\Geo\Models\Vehicle;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationCategories;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Models\Person;
use App\Domain\People\PersonReferences;
use App\Domain\Tasks\Models\Task;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class GeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeoService::class);
        // Remembers the assigned territories of the people it was asked about: per request, per job.
        $this->app->scoped(FieldAccess::class);
    }

    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('geo.territories.imported', EventCategory::Admin, EventSeverity::Notice),
            new EventType('geo.territory.created', EventCategory::Admin),
            new EventType('geo.territory.updated', EventCategory::Admin),
            new EventType('geo.territory.responsible_assigned', EventCategory::Admin),
            new EventType('geo.territory.responsible_removed', EventCategory::Admin),

            new EventType('geo.street.renamed', EventCategory::Admin),
            new EventType('geo.street.merged', EventCategory::Admin, EventSeverity::Notice),
            new EventType('geo.house.created', EventCategory::Business),
            new EventType('geo.house.updated', EventCategory::Business),
            new EventType('geo.house.apartments_added', EventCategory::Business),
            new EventType('geo.house.apartment_removed', EventCategory::Business),
            new EventType('geo.house.archived', EventCategory::Business, EventSeverity::Notice),
            new EventType('geo.house.restored', EventCategory::Business),
            new EventType('geo.assignment.created', EventCategory::Access, EventSeverity::Notice),
            new EventType('geo.assignment.ended', EventCategory::Access, EventSeverity::Notice),
            new EventType('geo.visit.recorded', EventCategory::Business),
            new EventType('geo.zone.created', EventCategory::Business),
            new EventType('geo.zone.updated', EventCategory::Business),
            new EventType('geo.zone.archived', EventCategory::Business, EventSeverity::Notice),
            new EventType('geo.zone.restored', EventCategory::Business),
            new EventType('geo.location.sharing_started', EventCategory::Security, EventSeverity::Notice),
            new EventType('geo.location.sharing_stopped', EventCategory::Security),
            new EventType('geo.location.track_viewed', EventCategory::Access, EventSeverity::Notice),
            new EventType('geo.location.purged', EventCategory::Data, EventSeverity::Notice),
            new EventType('geo.vehicle.created', EventCategory::Business),
            new EventType('geo.vehicle.updated', EventCategory::Business),
            new EventType('geo.vehicle.archived', EventCategory::Business, EventSeverity::Notice),
            new EventType('geo.vehicle.restored', EventCategory::Business),
            new EventType('geo.vehicle.tracker_key_issued', EventCategory::Security, EventSeverity::Notice),
            new EventType('geo.vehicle.tracker_key_revoked', EventCategory::Security, EventSeverity::Notice),
            new EventType('geo.settings.changed', EventCategory::Admin, EventSeverity::Notice),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([ImportTerritoriesCommand::class, PurgeLocationsCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('geo:purge-locations')->dailyAt('03:45')->withoutOverlapping();
        });

        $this->callAfterResolving(NotificationCategories::class, function (NotificationCategories $categories): void {
            $categories->register('field', 'geo', [NotificationCategories::IN_APP]);
        });

        $this->callAfterResolving(PersonReferences::class, function (PersonReferences $references): void {
            $references->register('houses', 'created_by_person_id');
            $references->register('apartments', 'last_visit_person_id');
            $references->register('visits', 'person_id');
            $references->register('apartment_notes', 'author_person_id');
            $references->register('field_assignments', 'person_id');
            $references->register('field_assignments', 'assigned_by_person_id');
            $references->register('field_assignments', 'ended_by_person_id');
            $references->register('house_appeals', 'linked_by_person_id');
            $references->register('geo_zones', 'created_by_person_id');
            $references->register('geo_zone_responsibles', 'person_id', ['geo_zone_id']);
            $references->register('geo_zone_links', 'linked_by_person_id');
            $references->register('vehicles', 'responsible_person_id');
            $references->register('location_shares', 'person_id');
            // Crossings and presences name the mover by kind and id: re-pointed for the kind "person" only.
            $references->registerHandler('geo_zone_movers', function (int $kept, int $merged): array {
                DB::table('geo_zone_presences')->where('mover_type', 'person')->where('mover_id', $merged)->delete();

                return ['crossings' => DB::table('geo_zone_crossings')->where('mover_type', 'person')->where('mover_id', $merged)->update(['mover_id' => $kept])];
            });
        });

        // An event with a point on the map is bound to the geozones the point stands in (ФО §6.11).
        // Bound once the action is through: by then the audience of the event is written, and whoever answers for
        // the zone is told only if they see the event.
        OrganizationEvent::saved(function (OrganizationEvent $event): void {
            if ($event->wasRecentlyCreated || $event->wasChanged(['latitude', 'longitude', 'cancelled_at'])) {
                DB::afterCommit(fn () => $this->app->make(ZoneBindings::class)->eventChanged($event));
            }
        });

        Event::listen(JobProcessing::class, fn () => $this->app->make(FieldAccess::class)->forget());

        $this->callAfterResolving(AuthorizationService::class, fn (AuthorizationService $authorization) => $this->configure($authorization));
    }

    private function configure(AuthorizationService $authorization): void
    {
        $org = $this->app->make(OrgStructure::class);
        $authorization->registerLocator(House::class, new TerritoryColumnLocator);
        $authorization->registerLocator(GeoZone::class, new TerritoryColumnLocator);
        $authorization->registerLocator(Vehicle::class, new UnitColumnLocator('org_unit_id'));
        $authorization->registerLocator(LocationShare::class, new PersonLocator(
            $org, fn (object $share): ?int => $share instanceof LocationShare ? $share->person_id : null, 'person_id',
        ));

        // "Св (закреплённые дома)": an agitator works with the houses they answer for — one by one or by territory.
        $assigned = fn (User $user, object $house): bool => $house instanceof House && $this->app->make(FieldAccess::class)->isAssigned($user->person_id, $house);
        $assignedQuery = fn (User $user, Builder $query) => $this->app->make(FieldAccess::class)->whereAssigned($query, $user->person_id);
        foreach (['geo.houses.read', 'geo.visits.create', 'geo.visits.read', 'geo.map.read'] as $code) {
            $authorization->addRelation($code, AuthorizationService::RELATION_RELATED, $assigned, $assignedQuery);
        }

        // "Т" of an employee: on the map — the houses of their own territories (the colour only, not the card).
        $territories = fn (User $user): array => $this->app->make(TerritorialAccess::class)->paths($user->person_id);
        $inside = function (?int $territoryId, array $paths): bool {
            $path = $territoryId !== null ? (string) Territory::query()->whereKey($territoryId)->value('path') : '';
            foreach ($paths as $own) {
                if ($path !== '' && $own !== '' && str_starts_with($path, $own)) {
                    return true;
                }
            }

            return false;
        };
        $authorization->addRelation('geo.map.read', AuthorizationService::RELATION_RELATED,
            fn (User $user, object $house): bool => $house instanceof House && $inside($house->territory_id, $territories($user)),
            function (User $user, Builder $query) use ($territories): void {
                $paths = $territories($user);
                $paths === [] ? $query->whereRaw('1 = 0') : $query->whereIn($query->getModel()->qualifyColumn('territory_id'), fn (QueryBuilder $sub) => $sub
                    ->select('id')->from('territories')->where(fn (QueryBuilder $where) => PersonLocator::likeAny($where, 'path', $paths)));
            });

        // A zone is seen by those whose territories it touches — in either direction, like a regional post —
        // by everybody when it belongs to the whole organization, and by the people who answer for it.
        $authorization->addRelation('geo.zones.read', AuthorizationService::RELATION_RELATED,
            function (User $user, object $zone) use ($territories): bool {
                if (! $zone instanceof GeoZone) {
                    return false;
                }
                if ($zone->territory_id === null || $zone->responsibles()->whereKey($user->person_id)->exists()) {
                    return true;
                }
                $path = (string) Territory::query()->whereKey($zone->territory_id)->value('path');
                foreach ($territories($user) as $own) {
                    if ($path !== '' && $own !== '' && (str_starts_with($path, $own) || str_starts_with($own, $path))) {
                        return true;
                    }
                }

                return false;
            },
            function (User $user, Builder $query) use ($territories): void {
                $paths = $territories($user);
                $ancestors = collect($paths)->flatMap(fn (string $path): array => array_map('intval', array_filter(explode('/', $path))))->unique()->values()->all();
                $column = $query->getModel()->qualifyColumn('territory_id');
                $query->where(fn (Builder $where) => $where->whereNull($column)
                    ->orWhereIn($column, $ancestors)
                    ->when($paths !== [], fn (Builder $w) => $w->orWhereIn($column, fn (QueryBuilder $sub) => $sub->select('id')->from('territories')
                        ->where(fn (QueryBuilder $in) => PersonLocator::likeAny($in, 'path', $paths))))
                    ->orWhereIn($query->getModel()->qualifyColumn('id'), fn (QueryBuilder $sub) => $sub->select('geo_zone_id')->from('geo_zone_responsibles')->where('person_id', $user->person_id)));
            });

        // ФО §6.11: «связанные задачи ("вернуться 12.10")». Whoever records visits may set themselves a task to
        // come back — and only that kind of task, and only for themselves.
        $returns = fn (User $user, object $task): bool => $task instanceof Task && $task->type_code === 'follow_up_visit'
            && $task->creator_person_id === $user->person_id && $task->project_id === null && $authorization->can($user, 'geo.visits.create');
        $authorization->addRelation('tasks.create', AuthorizationService::RELATION_GRANT, $returns);
        $authorization->addRelation('tasks.assign', AuthorizationService::RELATION_GRANT,
            fn (User $user, object $subject): bool => $returns($user, $subject)
                || ($subject instanceof Person && $subject->id === $user->person_id && $authorization->can($user, 'geo.visits.create')));
    }
}
