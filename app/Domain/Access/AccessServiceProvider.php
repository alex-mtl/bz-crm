<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Access\Console\ExpireAccessCommand;
use App\Domain\Access\Scopes\JournalLocator;
use App\Domain\Access\Scopes\PersonLocator;
use App\Domain\Access\Scopes\TreeNodeLocator;
use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Models\AccountLinkHint;
use App\Domain\People\Models\Person;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AccessServiceProvider extends ServiceProvider
{
    /** Events about viewing confidential layers (ФО §6.3.4). */
    public const array CONFIDENTIAL_ACCESS_EVENTS = ['profile.layer.viewed'];

    public function register(): void
    {
        $this->app->singleton(PermissionRegistry::class);
        // Per request / per job: caches of rights, memberships and territories must never outlive one.
        $this->app->scoped(TerritorialAccess::class);
        $this->app->scoped(AuthorizationService::class, function ($app): AuthorizationService {
            $service = new AuthorizationService($app->make(PermissionRegistry::class), $app->make(OrgStructure::class), $app->make(TerritorialAccess::class));
            $this->configure($service, $app->make(OrgStructure::class));

            return $service;
        });
    }

    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('access.role.created', EventCategory::Admin, EventSeverity::Notice),
            new EventType('access.role.updated', EventCategory::Admin),
            new EventType('access.role.permissions_changed', EventCategory::Admin, EventSeverity::Notice),
            new EventType('access.role.field_rules_changed', EventCategory::Admin, EventSeverity::Notice),
            new EventType('access.reserved_permission.granted', EventCategory::Security, EventSeverity::Warning),
            new EventType('access.role.assigned', EventCategory::Access, EventSeverity::Notice),
            new EventType('access.role.delegated', EventCategory::Access, EventSeverity::Notice),
            new EventType('access.role.revoked', EventCategory::Access, EventSeverity::Notice),
            new EventType('access.role.expired', EventCategory::Access, EventSeverity::Notice),
            new EventType('access.escalation.denied', EventCategory::Security, EventSeverity::Warning),
            new EventType('access.system_roles.synced', EventCategory::Admin),
            new EventType('access.simulated', EventCategory::Access, EventSeverity::Notice),
            new EventType('access.territory.granted', EventCategory::Access, EventSeverity::Notice),
            new EventType('access.territory.revoked', EventCategory::Access, EventSeverity::Notice),
            new EventType('access.territory.expired', EventCategory::Access, EventSeverity::Notice),
            new EventType('access.territory.grant_denied', EventCategory::Security, EventSeverity::Warning),
            new EventType('admission.invitation.sent', EventCategory::Access, EventSeverity::Notice),
            new EventType('admission.invitation.accepted', EventCategory::Access, EventSeverity::Notice),
            new EventType('admission.invitation.revoked', EventCategory::Access),
            new EventType('admission.application.approved', EventCategory::Access, EventSeverity::Notice),
            new EventType('admission.application.rejected', EventCategory::Access, EventSeverity::Notice),
        );

        // Every Laravel / Filament ability that is a catalog code goes through the one service (ТЗ §15).
        Gate::before(function (mixed $user, string $ability, array $arguments): ?bool {
            $permissions = $this->app->make(PermissionRegistry::class);
            if (! $user instanceof User || ! $permissions->has($ability)) {
                return null;
            }
            $subject = $arguments[0] ?? null;

            return $this->app->make(AuthorizationService::class)->can($user, $ability, is_object($subject) ? $subject : null);
        });

        // A worker process lives long: never reuse another job's cached rights.
        Event::listen(JobProcessing::class, fn () => $this->app->make(AuthorizationService::class)->forget());

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireAccessCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('access:expire')->everyFifteenMinutes()->withoutOverlapping();
        });
    }

    /**
     * Where the core objects live on the scope axes, and the relations that count for core codes.
     * Other modules add theirs from their own providers.
     */
    private function configure(AuthorizationService $authorization, OrgStructure $org): void
    {
        $authorization->registerLocator(Person::class, new PersonLocator($org, fn (object $p): ?int => $p instanceof Person ? $p->id : null, 'id'));
        $authorization->registerLocator(User::class, new PersonLocator($org, fn (object $u): ?int => $u instanceof User ? $u->person_id : null, 'person_id'));
        $authorization->registerLocator(OrgUnit::class, new TreeNodeLocator);
        $authorization->registerLocator(JournalEntry::class, new JournalLocator(new PersonLocator($org, fn (object $p): ?int => $p instanceof Person ? $p->id : null, 'id')));

        // ФО §6.3.4: who viewed whose confidential layer is itself visible only with a separate right.
        $authorization->setQueryScope('audit.read', function (User $user, Builder $q) use ($authorization): void {
            if (! $authorization->can($user, 'audit.read.confidential_access')) {
                $q->whereNotIn($q->getModel()->qualifyColumn('event_type'), self::CONFIDENTIAL_ACCESS_EVENTS);
            }
        });
        $authorization->addObjectRule('audit.read', fn (User $user, object $entry): bool => ! $entry instanceof JournalEntry
            || ! in_array($entry->event_type, self::CONFIDENTIAL_ACCESS_EVENTS, true)
            || $authorization->can($user, 'audit.read.confidential_access'));
        $authorization->registerLocator(Territory::class, new TreeNodeLocator);

        // Own card.
        $ownPerson = fn (User $user, object $subject): bool => ($subject instanceof Person && $subject->id === $user->person_id)
            || ($subject instanceof User && $subject->id === $user->id);
        $authorization->addRelation('people.read', AuthorizationService::RELATION_OWN, $ownPerson,
            fn (User $user, Builder $q) => $q->whereKey($q->getModel() instanceof User ? $user->id : $user->person_id));
        $authorization->addObjectRule('profile.own.update', fn (User $user, object $subject): bool => $subject instanceof Person && $subject->id === $user->person_id);
        // Д-26: everyone sees the cards of their own chain of command — the direct manager and those above — so
        // that even a volunteer, who otherwise sees only the people they work with, can write to their head.
        $personOf = fn (object $subject): ?int => match (true) {
            $subject instanceof Person => $subject->id,
            $subject instanceof User => $subject->person_id,
            default => null,
        };
        $authorization->addRelation('people.read', AuthorizationService::RELATION_RELATED,
            fn (User $user, object $subject): bool => in_array($personOf($subject), $org->managerChain($user->person_id), true),
            fn (User $user, Builder $q) => $q->whereIn(
                $q->getModel()->qualifyColumn($q->getModel() instanceof User ? 'person_id' : 'id'), $org->managerChain($user->person_id),
            ));

        // Д-10 (phase 2): a "possibly the same person" hint reaches the existing person's direct manager.
        $authorization->addRelation('people.link_hints.read', AuthorizationService::RELATION_GRANT,
            fn (User $user, object $hint): bool => $hint instanceof AccountLinkHint && $org->isDirectManager($user->person_id, $hint->existing_person_id),
            fn (User $user, Builder $q) => $q->whereIn($q->getModel()->qualifyColumn('existing_person_id'), fn (QueryBuilder $sub) => $sub
                ->select('person_id')->from('org_memberships')->where('manager_person_id', $user->person_id)));
    }
}
