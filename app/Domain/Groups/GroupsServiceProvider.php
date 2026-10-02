<?php

declare(strict_types=1);

namespace App\Domain\Groups;

use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Groups\Models\Group;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\ChatSubjects;
use App\Domain\Notifications\NotificationCategories;
use App\Domain\People\PersonReferences;
use Illuminate\Database\Eloquent\Model;
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

        // The chat of a group belongs to its members (ФО §6.5).
        $this->callAfterResolving(ChatSubjects::class, function (ChatSubjects $subjects): void {
            $subjects->register(
                Group::class,
                fn (User $user, Model $group): bool => $group instanceof Group && $this->app->make(GroupAccess::class)->isMember($group, $user->person_id),
                fn (Model $group): string => $group instanceof Group ? $group->name : '',
                fn (Model $group): string => '/admin/groups/'.$group->getKey(),
            );
        });

        $this->callAfterResolving(NotificationCategories::class, function (NotificationCategories $categories): void {
            $categories->register('groups', 'groups');
        });

        $this->callAfterResolving(PersonReferences::class, function (PersonReferences $references): void {
            $references->register('group_members', 'person_id', ['group_id']);
            $references->register('group_join_requests', 'person_id');
            $references->register('group_invitations', 'person_id');
        });
    }
}
