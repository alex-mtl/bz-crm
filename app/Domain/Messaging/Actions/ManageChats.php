<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\ChatAccess;
use App\Domain\Messaging\DirectMessagePolicy;
use App\Domain\Messaging\Events\ChatUpdated;
use App\Domain\Messaging\Exceptions\MessagingRuleViolation;
use App\Domain\Messaging\Models\Chat;
use App\Domain\Messaging\Models\ChatInvitation;
use App\Domain\Messaging\Models\ChatMember;
use App\Domain\Notifications\Retraction;
use App\Domain\People\Models\Person;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Chats themselves (ФО §6.6.1–6.6.2): starting a direct dialog, creating a group chat, its members and their
 * roles, invitation links, what each member wants to be notified about.
 */
final readonly class ManageChats
{
    public function __construct(
        private AuthorizationService $authorization,
        private ChatAccess $access,
        private DirectMessagePolicy $policy,
        private Retraction $retraction,
        private EventJournal $journal,
    ) {}

    /**
     * The dialog of the two — the existing one, or a new one if the sender may start it (Д-26). A dialog the
     * other person started is simply returned: answering is always allowed.
     */
    public function direct(User $actor, Person $other): Chat
    {
        $this->authorization->authorize($actor, 'chats.direct.create');
        if ($other->id === $actor->person_id) {
            throw MessagingRuleViolation::because('dialog_with_self');
        }
        $existing = Chat::query()->where('direct_key', Chat::directKey($actor->person_id, $other->id))->first();
        if ($existing !== null) {
            return $existing;
        }

        // A new dialog: only with a person the sender can see, who can sign in, and whom the table lets them write to.
        $this->authorization->authorize($actor, 'people.read', $other);
        $recipient = $other->user;
        if ($recipient === null || ! $recipient->isActive()) {
            throw MessagingRuleViolation::because('no_account');
        }
        if (! $this->policy->mayStart($actor, $recipient)) {
            throw new AuthorizationException(__('messaging.errors.direct_not_allowed'));
        }

        return DB::transaction(function () use ($actor, $other): Chat {
            $chat = Chat::query()->create([
                'type' => Chat::DIRECT, 'direct_key' => Chat::directKey($actor->person_id, $other->id), 'created_by_person_id' => $actor->person_id,
            ]);
            $this->join($chat, $actor->person_id, ChatMember::MEMBER);
            $this->join($chat, $other->id, ChatMember::MEMBER);
            $this->journal->record('messaging.chat.created', $chat, [], ['type' => Chat::DIRECT, 'with_person_id' => $other->id]);

            return $chat;
        });
    }

    /**
     * A group chat on a topic (ФО §6.6.2). Its creator owns it; the first members are people the creator can see.
     *
     * @param  list<int>  $memberPersonIds
     */
    public function createGroup(User $actor, string $title, array $memberPersonIds = []): Chat
    {
        $this->authorization->authorize($actor, 'chats.group.create');
        $title = trim($title);
        if ($title === '') {
            throw MessagingRuleViolation::because('title_required');
        }
        $people = $this->addable($actor, $memberPersonIds);

        return DB::transaction(function () use ($actor, $title, $people): Chat {
            $chat = Chat::query()->create(['type' => Chat::GROUP, 'title' => mb_substr($title, 0, 255), 'created_by_person_id' => $actor->person_id]);
            $this->join($chat, $actor->person_id, ChatMember::OWNER);
            foreach ($people as $person) {
                $this->join($chat, $person->id, ChatMember::MEMBER);
            }
            $this->journal->record('messaging.chat.created', $chat, [], ['type' => Chat::GROUP, 'title' => $title, 'members' => count($people) + 1]);

            return $chat;
        });
    }

    public function rename(User $actor, Chat $chat, string $title): void
    {
        $this->ensureManages($actor, $chat);
        $title = trim($title);
        if ($title === '') {
            throw MessagingRuleViolation::because('title_required');
        }
        $chat->update(['title' => mb_substr($title, 0, 255)]);
        $this->changed($chat, 'chat');
    }

    /**
     * @param  list<int>  $personIds
     * @return int members added
     */
    public function addMembers(User $actor, Chat $chat, array $personIds): int
    {
        $this->ensureManages($actor, $chat);
        $people = array_filter($this->addable($actor, $personIds), fn (Person $person): bool => $this->access->membership($chat, $person->id) === null);

        DB::transaction(function () use ($chat, $people): void {
            foreach ($people as $person) {
                $this->join($chat, $person->id, ChatMember::MEMBER);
                $this->journal->record('messaging.member.added', $chat, [], ['person_id' => $person->id]);
            }
        });
        $this->changed($chat, 'members');

        return count($people);
    }

    /**
     * The owner is never removed; an admin does not remove another admin — only the owner does.
     */
    public function removeMember(User $actor, Chat $chat, Person $person): void
    {
        $this->ensureManages($actor, $chat);
        $member = $this->memberOrFail($chat, $person->id);
        if ($member->role === ChatMember::OWNER || ($member->role === ChatMember::ADMIN && $this->access->roleOf($chat, $actor->person_id) !== ChatMember::OWNER)) {
            throw MessagingRuleViolation::because('cannot_remove');
        }
        $this->drop($chat, $member, 'removed');
    }

    public function leave(User $actor, Chat $chat): void
    {
        if (! $chat->isGroup()) {
            throw MessagingRuleViolation::because('cannot_leave');
        }
        $member = $this->memberOrFail($chat, $actor->person_id);
        if ($member->role === ChatMember::OWNER) {
            throw MessagingRuleViolation::because('owner_cannot_leave');
        }
        $this->drop($chat, $member, 'left');
    }

    /**
     * The owner hands out any role, the ownership included; an admin only makes moderators and back.
     */
    public function setRole(User $actor, Chat $chat, Person $person, string $role): void
    {
        $this->ensureManages($actor, $chat);
        if (! in_array($role, ChatMember::ROLES, true)) {
            throw MessagingRuleViolation::because('invalid_role');
        }
        $member = $this->memberOrFail($chat, $person->id);
        $byOwner = $this->access->roleOf($chat, $actor->person_id) === ChatMember::OWNER;
        if ($member->role === ChatMember::OWNER || (! $byOwner && (in_array($role, ChatMember::MANAGERS, true) || $member->role === ChatMember::ADMIN))) {
            throw MessagingRuleViolation::because('role_not_allowed');
        }
        if ($member->role === $role) {
            return;
        }

        DB::transaction(function () use ($chat, $member, $role): void {
            $old = $member->role;
            if ($role === ChatMember::OWNER) {
                ChatMember::query()->where('chat_id', $chat->id)->where('role', ChatMember::OWNER)->update(['role' => ChatMember::ADMIN]);
            }
            $member->update(['role' => $role]);
            $this->journal->record('messaging.member.role_changed', $chat, ['role' => $old], ['person_id' => $member->person_id, 'role' => $role]);
        });
        $this->access->forget();
        $this->changed($chat, 'members');
    }

    /**
     * @return array{invitation: ChatInvitation, token: string}
     */
    public function inviteByLink(User $actor, Chat $chat, ?Carbon $expiresAt = null, ?int $maxUses = null): array
    {
        $this->ensureManages($actor, $chat);
        $token = Str::random(40);

        $invitation = DB::transaction(function () use ($actor, $chat, $token, $expiresAt, $maxUses): ChatInvitation {
            $invitation = ChatInvitation::query()->create([
                'chat_id' => $chat->id, 'token_hash' => ChatInvitation::hashToken($token), 'expires_at' => $expiresAt,
                'max_uses' => $maxUses, 'created_by_person_id' => $actor->person_id,
            ]);
            $this->journal->record('messaging.link.created', $chat, [], ['expires_at' => $expiresAt?->toIso8601String(), 'max_uses' => $maxUses]);

            return $invitation;
        });

        return ['invitation' => $invitation, 'token' => $token];
    }

    public function revokeLink(User $actor, ChatInvitation $invitation): void
    {
        $this->ensureManages($actor, $invitation->chat);
        if ($invitation->revoked_at !== null) {
            return;
        }

        DB::transaction(function () use ($invitation): void {
            $invitation->update(['revoked_at' => now()]);
            $this->journal->record('messaging.link.revoked', $invitation->chat);
        });
    }

    public function joinByLink(User $actor, string $token): Chat
    {
        $this->authorization->authorize($actor, 'chats.write');
        $invitation = ChatInvitation::query()->with('chat')->where('token_hash', ChatInvitation::hashToken($token))->first();
        if ($invitation === null || ! $invitation->isUsable() || $invitation->chat->archived_at !== null) {
            throw MessagingRuleViolation::because('link_unusable');
        }
        $chat = $invitation->chat;
        if ($this->access->membership($chat, $actor->person_id) !== null) {
            return $chat;
        }

        DB::transaction(function () use ($actor, $invitation, $chat): void {
            $invitation->increment('uses');
            $this->join($chat, $actor->person_id, ChatMember::MEMBER);
            $this->journal->record('messaging.member.added', $chat, [], ['person_id' => $actor->person_id, 'how' => 'link']);
        });
        $this->changed($chat, 'members');

        return $chat;
    }

    /**
     * "Все сообщения / только упоминания / мут" (ФО §6.6.4) — the member's own choice for this chat.
     */
    public function setNotify(User $actor, Chat $chat, string $level): void
    {
        if (! in_array($level, ChatMember::NOTIFY, true)) {
            throw MessagingRuleViolation::because('invalid_notify');
        }
        if (! $this->access->mayRead($actor, $chat)) {
            throw new AuthorizationException(__('access.denied'));
        }
        $this->ensureMember($chat, $actor->person_id)->update(['notify' => $level]);
        $this->access->forget();
    }

    /**
     * The membership row of a person who may be in the chat: for the discussion of an object it appears the first
     * time the person writes or changes a setting there.
     */
    public function ensureMember(Chat $chat, int $personId): ChatMember
    {
        return $this->access->membership($chat, $personId) ?? $this->join($chat, $personId, ChatMember::MEMBER);
    }

    public function archive(User $actor, Chat $chat): void
    {
        if ($this->access->roleOf($chat, $actor->person_id) !== ChatMember::OWNER || ! $chat->isGroup()) {
            throw new AuthorizationException(__('access.denied'));
        }
        DB::transaction(function () use ($chat): void {
            $chat->update(['archived_at' => now()]);
            $this->journal->record('messaging.chat.archived', $chat);
        });
        $this->changed($chat, 'chat');
    }

    /**
     * People the actor may bring into a chat: those they can see and who can sign in.
     *
     * @param  list<int>  $personIds
     * @return list<Person>
     */
    private function addable(User $actor, array $personIds): array
    {
        $people = [];
        foreach (Person::query()->with('user')->whereKey(array_values(array_unique(array_map('intval', $personIds))))->get() as $person) {
            if ($person->id === $actor->person_id) {
                continue;
            }
            $this->authorization->authorize($actor, 'people.read', $person);
            if ($person->user?->isActive() !== true) {
                throw MessagingRuleViolation::because('no_account');
            }
            $people[] = $person;
        }

        return $people;
    }

    private function join(Chat $chat, int $personId, string $role): ChatMember
    {
        $member = ChatMember::query()->create([
            'chat_id' => $chat->id, 'person_id' => $personId, 'role' => $role, 'joined_at' => now(),
            // Someone who joins later does not get the whole history as "unread".
            'last_read_message_id' => $chat->messages()->max('id'),
        ]);
        $this->access->forget();

        return $member;
    }

    private function drop(Chat $chat, ChatMember $member, string $how): void
    {
        DB::transaction(function () use ($chat, $member, $how): void {
            $member->delete();
            $this->journal->record('messaging.member.removed', $chat, [], ['person_id' => $member->person_id, 'how' => $how]);
        });
        $this->access->forget();
        // ТЗ §37: what the person was told about this chat no longer names it.
        $userId = User::query()->where('person_id', $member->person_id)->value('id');
        if ($userId !== null) {
            $this->retraction->retract('chat', $chat->id, [(int) $userId]);
        }
        $this->changed($chat, 'members');
    }

    private function memberOrFail(Chat $chat, int $personId): ChatMember
    {
        return $this->access->membership($chat, $personId) ?? throw MessagingRuleViolation::because('not_a_member');
    }

    private function ensureManages(User $actor, Chat $chat): void
    {
        if (! $this->access->canManage($actor, $chat) || $chat->archived_at !== null) {
            throw new AuthorizationException(__('access.denied'));
        }
    }

    private function changed(Chat $chat, string $what): void
    {
        $userIds = User::query()->whereIn('person_id', ChatMember::query()->where('chat_id', $chat->id)->select('person_id'))->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
        event(new ChatUpdated($chat->id, $what, null, $userIds));
    }
}
