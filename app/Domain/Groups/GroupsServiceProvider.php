<?php

declare(strict_types=1);

namespace App\Domain\Groups;

use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\People\PersonReferences;
use Illuminate\Support\ServiceProvider;

final class GroupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(GroupAccess::class);
    }

    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('groups.group.created', EventCategory::Business),
            new EventType('groups.group.updated', EventCategory::Business),
            new EventType('groups.group.archived', EventCategory::Business),
            new EventType('groups.member.joined', EventCategory::Business),
            new EventType('groups.member.left', EventCategory::Business),
            new EventType('groups.member.role_changed', EventCategory::Business),
            new EventType('groups.request.submitted', EventCategory::Business),
            new EventType('groups.request.rejected', EventCategory::Business),
            new EventType('groups.invitation.sent', EventCategory::Business),
            new EventType('groups.invitation.bulk_sent', EventCategory::Business),
            new EventType('groups.invitation.link_created', EventCategory::Business),
            new EventType('groups.invitation.declined', EventCategory::Business),
            new EventType('groups.invitation.revoked', EventCategory::Business),
        );

        $this->callAfterResolving(PersonReferences::class, function (PersonReferences $references): void {
            $references->register('group_members', 'person_id', ['group_id']);
            $references->register('group_join_requests', 'person_id');
            $references->register('group_invitations', 'person_id');
        });
    }
}
