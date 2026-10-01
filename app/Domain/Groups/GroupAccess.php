<?php

declare(strict_types=1);

namespace App\Domain\Groups;

use App\Domain\Access\AuthorizationService;
use App\Domain\Groups\Models\Group;
use App\Domain\Groups\Models\GroupMember;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Who sees and runs a group (ФО §6.5, ТЗ §19). Open and closed groups are seen by everyone with groups.read;
 * a secret group exists only for its members — in lists, counters, search and pickers alike, because every
 * query goes through visible().
 */
final class GroupAccess
{
    /** @var array<string, string|null> "group:person" => role */
    private array $roles = [];

    public function __construct(private readonly AuthorizationService $authorization) {}

    /**
     * @return Builder<Group>
     */
    public function visible(User $user): Builder
    {
        $query = Group::query();
        if (! $this->authorization->can($user, 'groups.read')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $where) => $where
            ->where(fn (Builder $open) => $open->where('type', '!=', Group::SECRET)->whereNull('archived_at'))
            // A member sees their group whatever its type, also after it was archived.
            ->orWhereIn('id', fn (QueryBuilder $sub) => $sub->select('group_id')->from('group_members')->where('person_id', $user->person_id)));
    }

    public function canSee(User $user, Group $group): bool
    {
        if (! $this->authorization->can($user, 'groups.read')) {
            return false;
        }

        return $this->roleOf($group, $user->person_id) !== null || (! $group->isSecret() && $group->archived_at === null);
    }

    public function roleOf(Group $group, int $personId): ?string
    {
        $key = $group->id.':'.$personId;
        if (! array_key_exists($key, $this->roles)) {
            $role = GroupMember::query()->where('group_id', $group->id)->where('person_id', $personId)->value('role');
            $this->roles[$key] = $role !== null ? (string) $role : null;
        }

        return $this->roles[$key];
    }

    public function isMember(Group $group, int $personId): bool
    {
        return $this->roleOf($group, $personId) !== null;
    }

    /**
     * The owner and the admins of the group; the holder of groups.manage as a system right — for any group they see.
     */
    public function canManage(User $user, Group $group): bool
    {
        return $this->canSee($user, $group) && (
            in_array($this->roleOf($group, $user->person_id), GroupMember::MANAGERS, true)
            || $this->authorization->can($user, 'groups.manage')
        );
    }

    public function canModerate(User $user, Group $group): bool
    {
        return $this->canSee($user, $group) && (
            in_array($this->roleOf($group, $user->person_id), GroupMember::MODERATORS, true)
            || $this->authorization->can($user, 'groups.moderate')
        );
    }

    /**
     * Ids of the groups the person belongs to — for the feed and post visibility.
     *
     * @return list<int>
     */
    public function groupIdsOf(int $personId): array
    {
        return GroupMember::query()->where('person_id', $personId)->pluck('group_id')->map(fn ($id): int => (int) $id)->all();
    }

    public function forget(): void
    {
        $this->roles = [];
    }
}
