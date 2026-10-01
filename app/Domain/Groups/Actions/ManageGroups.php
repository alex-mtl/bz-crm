<?php

declare(strict_types=1);

namespace App\Domain\Groups\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Geo\Models\Territory;
use App\Domain\Groups\Exceptions\GroupRuleViolation;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\Group;
use App\Domain\Groups\Models\GroupInvitation;
use App\Domain\Groups\Models\GroupJoinRequest;
use App\Domain\Groups\Models\GroupMember;
use App\Domain\Groups\Notifications\GroupNotice;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Discussions;
use App\Domain\Messaging\Models\Message;
use App\Domain\Notifications\Retraction;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\People\Models\Person;
use App\Domain\Projects\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Groups (ФО §6.5): creating, joining, requests, invitations — personal, by link and in bulk — and roles inside
 * the group. Every change of the membership keeps the chat of the group in step.
 */
final readonly class ManageGroups
{
    public const int BULK_LIMIT = 1000;

    public function __construct(
        private AuthorizationService $authorization,
        private GroupAccess $access,
        private Discussions $discussions,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, rules?: string|null, cover_code?: string|null, type?: string,
     *               org_unit_id?: int|null, territory_id?: int|null, project_id?: int|null}  $data
     */
    public function create(User $actor, array $data): Group
    {
        $this->authorization->authorize($actor, 'groups.create');
        $attributes = $this->validated($actor, $data, null);

        return DB::transaction(function () use ($actor, $attributes): Group {
            $group = Group::query()->create([...$attributes, 'created_by_person_id' => $actor->person_id]);
            $this->addMember($group, $actor->person_id, GroupMember::OWNER);
            $this->discussions->forSubject($group);
            $this->syncChat($group);
            $this->journal->record('groups.group.created', $group, [], $group->only(['name', 'type', 'org_unit_id', 'territory_id', 'project_id']));

            return $group;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Group $group, array $data): Group
    {
        $this->ensureManages($actor, $group);
        $attributes = $this->validated($actor, $data, $group);

        return DB::transaction(function () use ($group, $attributes): Group {
            $group->fill($attributes);
            $dirty = array_keys($group->getDirty());
            if ($dirty !== []) {
                $old = array_intersect_key($group->getOriginal(), array_flip(array_intersect($dirty, ['type', 'org_unit_id', 'territory_id', 'project_id'])));
                $group->save();
                $this->journal->record('groups.group.updated', $group, $old, [...array_intersect_key($group->getAttributes(), $old), 'fields' => $dirty]);
            }

            return $group;
        });
    }

    public function archive(User $actor, Group $group): void
    {
        if ($this->access->roleOf($group, $actor->person_id) !== GroupMember::OWNER && ! $this->authorization->can($actor, 'groups.manage')) {
            throw new AuthorizationException(__('access.denied'));
        }
        $this->ensureManages($actor, $group);

        DB::transaction(function () use ($group): void {
            $group->update(['archived_at' => now()]);
            $this->journal->record('groups.group.archived', $group);
        });
    }

    /**
     * Open group — a member at once; closed — a request (or an accepted invitation); secret — by invitation only.
     */
    public function join(User $actor, Group $group, ?string $message = null): GroupMember|GroupJoinRequest
    {
        $this->authorization->authorize($actor, 'groups.join');
        if (! $this->access->canSee($actor, $group) || $group->archived_at !== null) {
            throw new AuthorizationException(__('access.denied'));
        }
        if ($this->access->isMember($group, $actor->person_id)) {
            throw GroupRuleViolation::because('already_member');
        }
        if ($group->type === Group::OPEN) {
            return DB::transaction(fn (): GroupMember => $this->admit($group, $actor->person_id, 'joined'));
        }
        if (GroupJoinRequest::query()->where('group_id', $group->id)->where('person_id', $actor->person_id)->where('status', GroupJoinRequest::PENDING)->exists()) {
            throw GroupRuleViolation::because('request_pending');
        }

        return DB::transaction(function () use ($actor, $group, $message): GroupJoinRequest {
            $request = GroupJoinRequest::query()->create([
                'group_id' => $group->id, 'person_id' => $actor->person_id, 'message' => filled($message) ? trim((string) $message) : null,
            ]);
            $this->journal->record('groups.request.submitted', $group, [], ['person_id' => $actor->person_id]);

            return $request;
        });
    }

    public function decideRequest(User $actor, GroupJoinRequest $request, bool $approve): void
    {
        $group = $request->group;
        $this->ensureManages($actor, $group);
        if ($request->status !== GroupJoinRequest::PENDING) {
            throw GroupRuleViolation::because('request_decided');
        }

        DB::transaction(function () use ($actor, $request, $group, $approve): void {
            $request->update(['status' => $approve ? GroupJoinRequest::APPROVED : GroupJoinRequest::REJECTED, 'decided_by_user_id' => $actor->id, 'decided_at' => now()]);
            if ($approve && ! $this->access->isMember($group, $request->person_id)) {
                $this->admit($group, $request->person_id, 'request_approved');
            } else {
                $this->journal->record('groups.request.rejected', $group, [], ['person_id' => $request->person_id]);
            }
        });
        $request->person->user?->notify(new GroupNotice($approve ? GroupNotice::REQUEST_APPROVED : GroupNotice::REQUEST_REJECTED, $group->name, $group->id));
    }

    public function leave(User $actor, Group $group): void
    {
        $member = $this->memberOrFail($group, $actor->person_id);
        if ($member->role === GroupMember::OWNER) {
            throw GroupRuleViolation::because('owner_cannot_leave');
        }

        DB::transaction(fn () => $this->drop($group, $member, 'left'));
    }

    public function removeMember(User $actor, Group $group, Person $person): void
    {
        $this->ensureManages($actor, $group);
        $member = $this->memberOrFail($group, $person->id);
        $actorRole = $this->access->roleOf($group, $actor->person_id);
        // The owner is never removed; an admin does not remove another admin — only the owner does.
        if ($member->role === GroupMember::OWNER || ($member->role === GroupMember::ADMIN && $actorRole === GroupMember::ADMIN)) {
            throw GroupRuleViolation::because('cannot_remove');
        }

        DB::transaction(fn () => $this->drop($group, $member, 'removed'));
        $this->retractIfUnseen($group, $person->id);
    }

    /**
     * ТЗ §37: a person who can no longer see the group (a secret one they were invited to or removed from) keeps
     * the fact of the notifications about it, not the name of the group.
     */
    private function retractIfUnseen(Group $group, ?int $personId): void
    {
        $user = $personId !== null ? User::query()->where('person_id', $personId)->first() : null;
        $this->access->forget();
        if ($user !== null && ! $this->access->canSee($user, $group)) {
            app(Retraction::class)->retract('group', $group->id, [$user->id]);
        }
    }

    /**
     * The owner hands out any role, including the ownership itself; an admin only makes moderators and back.
     */
    public function setRole(User $actor, Group $group, Person $person, string $role): void
    {
        $this->ensureManages($actor, $group);
        if (! in_array($role, GroupMember::ROLES, true)) {
            throw GroupRuleViolation::because('invalid_role');
        }
        $member = $this->memberOrFail($group, $person->id);
        $actorRole = $this->access->roleOf($group, $actor->person_id);
        $byOwner = $actorRole === GroupMember::OWNER || ($actorRole === null && $this->authorization->can($actor, 'groups.manage'));
        if ($member->role === GroupMember::OWNER
            || (! $byOwner && (in_array($role, GroupMember::MANAGERS, true) || $member->role === GroupMember::ADMIN))) {
            throw GroupRuleViolation::because('role_not_allowed');
        }
        if ($member->role === $role) {
            return;
        }

        DB::transaction(function () use ($group, $member, $role): void {
            $old = $member->role;
            if ($role === GroupMember::OWNER) {
                GroupMember::query()->where('group_id', $group->id)->where('role', GroupMember::OWNER)->update(['role' => GroupMember::ADMIN]);
            }
            $member->update(['role' => $role]);
            $this->access->forget();
            $this->journal->record('groups.member.role_changed', $group, ['role' => $old], ['person_id' => $member->person_id, 'role' => $role]);
        });
    }

    public function invite(User $actor, Group $group, Person $person): GroupInvitation
    {
        $this->ensureManages($actor, $group);
        $this->authorization->authorize($actor, 'people.read', $person);
        $this->ensureInvitable($group, $person);

        $invitation = DB::transaction(fn (): GroupInvitation => $this->issue($group, $person->id, $actor->person_id));
        $person->user?->notify(new GroupNotice(GroupNotice::INVITED, $group->name, $group->id));

        return $invitation;
    }

    /**
     * Invites everyone from the given selection the actor's bulk right reaches (ФО §6.5 "по фильтру").
     * The selection is a query over people built by the caller; the scope is applied here.
     *
     * @param  Builder<Person>  $people
     * @return int invitations issued
     */
    public function inviteBulk(User $actor, Group $group, Builder $people): int
    {
        $this->ensureManages($actor, $group);
        $this->authorization->authorize($actor, 'groups.invite.bulk');

        $candidates = $this->authorization->scopeQuery($actor, 'groups.invite.bulk', $people)
            ->whereHas('user', fn (Builder $user) => $user->where('status', UserStatus::Active))
            ->whereNotIn('people.id', GroupMember::query()->where('group_id', $group->id)->select('person_id'))
            ->whereNotIn('people.id', GroupInvitation::query()->where('group_id', $group->id)->where('status', GroupInvitation::PENDING)->whereNotNull('person_id')->select('person_id'))
            ->limit(self::BULK_LIMIT + 1)->get();
        if ($candidates->count() > self::BULK_LIMIT) {
            throw GroupRuleViolation::because('bulk_too_large', ['limit' => self::BULK_LIMIT]);
        }

        DB::transaction(function () use ($actor, $group, $candidates): void {
            foreach ($candidates as $person) {
                $this->issue($group, $person->id, $actor->person_id, journal: false);
            }
            $this->journal->record('groups.invitation.bulk_sent', $group, [], ['count' => $candidates->count()]);
        });
        foreach ($candidates as $person) {
            $person->user?->notify(new GroupNotice(GroupNotice::INVITED, $group->name, $group->id));
        }

        return $candidates->count();
    }

    /**
     * @return array{invitation: GroupInvitation, token: string}
     */
    public function inviteByLink(User $actor, Group $group, ?Carbon $expiresAt = null, ?int $maxUses = null): array
    {
        $this->ensureManages($actor, $group);
        $token = Str::random(40);

        $invitation = DB::transaction(function () use ($actor, $group, $token, $expiresAt, $maxUses): GroupInvitation {
            $invitation = GroupInvitation::query()->create([
                'group_id' => $group->id, 'token_hash' => GroupInvitation::hashToken($token),
                'expires_at' => $expiresAt, 'max_uses' => $maxUses, 'invited_by_person_id' => $actor->person_id,
            ]);
            $this->journal->record('groups.invitation.link_created', $group, [], ['expires_at' => $expiresAt?->toIso8601String(), 'max_uses' => $maxUses]);

            return $invitation;
        });

        return ['invitation' => $invitation, 'token' => $token];
    }

    public function joinByLink(User $actor, string $token): GroupMember
    {
        $this->authorization->authorize($actor, 'groups.join');
        $invitation = GroupInvitation::query()->where('token_hash', GroupInvitation::hashToken($token))->first();
        if ($invitation === null || ! $invitation->isUsable() || $invitation->group->archived_at !== null) {
            throw GroupRuleViolation::because('invitation_unusable');
        }
        $group = $invitation->group;
        if ($this->access->isMember($group, $actor->person_id)) {
            throw GroupRuleViolation::because('already_member');
        }

        return DB::transaction(function () use ($actor, $invitation, $group): GroupMember {
            $invitation->increment('uses');

            return $this->admit($group, $actor->person_id, 'link');
        });
    }

    public function answerInvitation(User $actor, GroupInvitation $invitation, bool $accept): void
    {
        if ($invitation->person_id !== $actor->person_id || ! $invitation->isUsable()) {
            throw GroupRuleViolation::because('invitation_unusable');
        }
        $group = $invitation->group;

        DB::transaction(function () use ($actor, $invitation, $group, $accept): void {
            $invitation->update(['status' => $accept ? GroupInvitation::ACCEPTED : GroupInvitation::DECLINED, 'answered_at' => now()]);
            if ($accept && ! $this->access->isMember($group, $actor->person_id)) {
                $this->admit($group, $actor->person_id, 'invitation');
            } elseif (! $accept) {
                $this->journal->record('groups.invitation.declined', $group, [], ['person_id' => $actor->person_id]);
            }
        });
    }

    public function revokeInvitation(User $actor, GroupInvitation $invitation): void
    {
        $this->ensureManages($actor, $invitation->group);
        if ($invitation->status !== GroupInvitation::PENDING) {
            throw GroupRuleViolation::because('invitation_unusable');
        }

        DB::transaction(function () use ($invitation): void {
            $invitation->update(['status' => GroupInvitation::REVOKED, 'answered_at' => now()]);
            $this->journal->record('groups.invitation.revoked', $invitation->group, [], ['person_id' => $invitation->person_id, 'link' => $invitation->isLink()]);
        });
        $this->retractIfUnseen($invitation->group, $invitation->person_id);
    }

    /**
     * Pending personal invitations of the user — the only place a secret group shows up before joining.
     *
     * @return Builder<GroupInvitation>
     */
    public function invitationsFor(User $user): Builder
    {
        return GroupInvitation::query()->with('group')->where('person_id', $user->person_id)->where('status', GroupInvitation::PENDING);
    }

    /**
     * The chat of the group (ФО §6.5): members write, members read.
     */
    public function post(User $actor, Group $group, string $body): Message
    {
        if (! $this->access->isMember($group, $actor->person_id) || $group->archived_at !== null) {
            throw new AuthorizationException(__('access.denied'));
        }
        if (trim($body) === '') {
            throw GroupRuleViolation::because('empty_message');
        }

        return $this->discussions->post($this->discussions->forSubject($group), $actor->person_id, $body);
    }

    /**
     * @return Collection<int, Message>
     */
    public function messages(User $viewer, Group $group): Collection
    {
        if (! $this->access->isMember($group, $viewer->person_id)) {
            return new Collection;
        }

        return $this->discussions->forSubject($group)->messages()->with('author')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(User $actor, array $data, ?Group $group): array
    {
        $attributes = array_intersect_key($data, array_flip(['name', 'description', 'rules', 'cover_code', 'type', 'org_unit_id', 'territory_id', 'project_id']));
        if ($group === null || array_key_exists('name', $attributes)) {
            $attributes['name'] = trim((string) ($attributes['name'] ?? ''));
            if ($attributes['name'] === '') {
                throw GroupRuleViolation::because('name_required');
            }
        }
        $attributes['type'] ??= $group !== null ? $group->type : Group::OPEN;
        if (! in_array($attributes['type'], Group::TYPES, true)) {
            throw GroupRuleViolation::because('invalid_type');
        }

        // Binding a group to a unit, a territory or a project is a right of its own (catalog §6).
        foreach (['org_unit_id' => OrgUnit::class, 'territory_id' => Territory::class, 'project_id' => Project::class] as $key => $model) {
            $value = $attributes[$key] ?? null;
            if ($value === null || ($group !== null && (int) $group->getAttribute($key) === (int) $value)) {
                continue;
            }
            $this->authorization->authorize($actor, 'groups.link.manage', $model::query()->findOrFail($value));
        }

        return $attributes;
    }

    private function ensureManages(User $actor, Group $group): void
    {
        if (! $this->access->canManage($actor, $group)) {
            throw new AuthorizationException(__('access.denied'));
        }
    }

    private function ensureInvitable(Group $group, Person $person): void
    {
        if ($person->user?->isActive() !== true) {
            throw GroupRuleViolation::because('not_a_user');
        }
        if ($this->access->isMember($group, $person->id)) {
            throw GroupRuleViolation::because('already_member');
        }
        if (GroupInvitation::query()->where('group_id', $group->id)->where('person_id', $person->id)->where('status', GroupInvitation::PENDING)->exists()) {
            throw GroupRuleViolation::because('already_invited');
        }
    }

    private function issue(Group $group, int $personId, int $inviterPersonId, bool $journal = true): GroupInvitation
    {
        $invitation = GroupInvitation::query()->create(['group_id' => $group->id, 'person_id' => $personId, 'invited_by_person_id' => $inviterPersonId]);
        if ($journal) {
            $this->journal->record('groups.invitation.sent', $group, [], ['person_id' => $personId]);
        }

        return $invitation;
    }

    private function memberOrFail(Group $group, int $personId): GroupMember
    {
        $member = GroupMember::query()->where('group_id', $group->id)->where('person_id', $personId)->first();
        if ($member === null) {
            throw GroupRuleViolation::because('not_a_member');
        }

        return $member;
    }

    private function admit(Group $group, int $personId, string $how): GroupMember
    {
        $member = $this->addMember($group, $personId, GroupMember::MEMBER);
        // Whatever was pending for this person in this group is settled by the membership itself.
        GroupJoinRequest::query()->where('group_id', $group->id)->where('person_id', $personId)->where('status', GroupJoinRequest::PENDING)
            ->update(['status' => GroupJoinRequest::APPROVED, 'decided_at' => now()]);
        GroupInvitation::query()->where('group_id', $group->id)->where('person_id', $personId)->where('status', GroupInvitation::PENDING)
            ->update(['status' => GroupInvitation::ACCEPTED, 'answered_at' => now()]);
        $this->syncChat($group);
        $this->journal->record('groups.member.joined', $group, [], ['person_id' => $personId, 'how' => $how]);

        return $member;
    }

    private function addMember(Group $group, int $personId, string $role): GroupMember
    {
        $this->access->forget();

        return GroupMember::query()->create(['group_id' => $group->id, 'person_id' => $personId, 'role' => $role, 'joined_at' => now()]);
    }

    private function drop(Group $group, GroupMember $member, string $how): void
    {
        $member->delete();
        $this->access->forget();
        $chat = $this->discussions->findFor($group);
        if ($chat !== null) {
            DB::table('chat_members')->where('chat_id', $chat->id)->where('person_id', $member->person_id)->delete();
        }
        $this->journal->record('groups.member.left', $group, [], ['person_id' => $member->person_id, 'how' => $how]);
    }

    private function syncChat(Group $group): void
    {
        $this->discussions->syncMembers(
            $this->discussions->forSubject($group),
            GroupMember::query()->where('group_id', $group->id)->pluck('person_id')->map(fn ($id): int => (int) $id)->all(),
        );
    }
}
