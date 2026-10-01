<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use Illuminate\Support\ServiceProvider;

final class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per request / per job: memberships must not be cached across jobs.
        $this->app->scoped(OrgStructure::class);
    }

    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('org.unit.created', EventCategory::Admin, EventSeverity::Notice),
            new EventType('org.unit.updated', EventCategory::Admin),
            new EventType('org.unit.moved', EventCategory::Admin, EventSeverity::Notice),
            new EventType('org.unit.archived', EventCategory::Admin, EventSeverity::Notice),
            new EventType('org.unit.territories_changed', EventCategory::Access, EventSeverity::Notice),
            new EventType('org.unit.head_changed', EventCategory::Access, EventSeverity::Notice),
            new EventType('org.membership.created', EventCategory::Data, EventSeverity::Notice),
            new EventType('org.membership.transferred', EventCategory::Access, EventSeverity::Notice),
            new EventType('org.membership.position_changed', EventCategory::Data),
            new EventType('org.manager.changed', EventCategory::Access, EventSeverity::Notice),
        );
    }
}
