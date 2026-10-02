<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\Chat;
use App\Domain\Messaging\Models\ChatMember;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who reads and runs a chat (ФО §6.6, каталог §8). A direct or a group chat belongs to its members and to
 * nobody else — no role of the organization opens it. The discussion of an object follows the object.
 * The list of chats, the search, forwarding, notifications and the WebSocket channel all ask here.
 */
final class ChatAccess
{
    /** @var array<int, list<int>> user id => ids of the chats the user may read */
    private array $readable = [];

    /** @var array<string, ChatMember|null> "chat:person" => membership */
    private array $memberships = [];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ChatSubjects $subjects,
    ) {}

    /**
     * Ids of the chats shown to the user: the chats they are a member of — and, for discussions of objects,
     * only while they still may read the object.
     *
     * @return list<int>
     */
    public function chatIdsFor(User $user): array
    {
        if (isset($this->readable[$user->id])) {
            return $this->readable[$user->id];
        }
        if (! $this->authorization->can($user, 'chats.read')) {
            return $this->readable[$user->id] = [];
        }
        $chats = Chat::query()->whereIn('id', ChatMember::query()->where('person_id', $user->person_id)->select('chat_id'))->get();
        $ids = [];
        foreach ($chats as $chat) {
            if (! $chat->isSubject() || $this->subjects->mayAccess($user, $chat)) {
                $ids[] = $chat->id;
            }
        }

        return $this->readable[$user->id] = $ids;
    }

    /**
     * @return Builder<Chat>
     */
    public function visible(User $user): Builder
    {
        return Chat::query()->whereKey($this->chatIdsFor($user));
    }

    public function mayRead(User $user, Chat $chat): bool
    {
        if (! $this->authorization->can($user, 'chats.read')) {
            return false;
        }

        // The discussion of an object is open to whoever may read the object, member of the chat or not yet.
        return $chat->isSubject() ? $this->subjects->mayAccess($user, $chat) : $this->membership($chat, $user->person_id) !== null;
    }

    public function mayWrite(User $user, Chat $chat): bool
    {
        return $chat->archived_at === null && $this->mayRead($user, $chat) && $this->authorization->can($user, 'chats.write');
    }

    public function membership(Chat $chat, int $personId): ?ChatMember
    {
        $key = $chat->id.':'.$personId;
        if (! array_key_exists($key, $this->memberships)) {
            $this->memberships[$key] = ChatMember::query()->where('chat_id', $chat->id)->where('person_id', $personId)->first();
        }

        return $this->memberships[$key];
    }

    public function roleOf(Chat $chat, int $personId): ?string
    {
        return $this->membership($chat, $personId)?->role;
    }

    /**
     * Members, roles, links, the title — the owner and the admins of a group chat ("Св", каталог §8).
     */
    public function canManage(User $user, Chat $chat): bool
    {
        return $chat->isGroup() && in_array($this->roleOf($chat, $user->person_id), ChatMember::MANAGERS, true);
    }

    /**
     * Deleting somebody's messages and pinning: the moderators of a group chat; in a dialog — either of the two.
     */
    public function canModerate(User $user, Chat $chat): bool
    {
        return match (true) {
            $chat->isGroup() => in_array($this->roleOf($chat, $user->person_id), ChatMember::MODERATORS, true),
            default => false,
        };
    }

    public function mayPin(User $user, Chat $chat): bool
    {
        return $this->mayWrite($user, $chat) && ($chat->isDirect() || $this->canModerate($user, $chat));
    }

    /**
     * "@all" — by a right of the organization, or by running the chat (каталог §8).
     */
    public function mayMentionAll(User $user, Chat $chat): bool
    {
        return $this->mayWrite($user, $chat) && ($this->authorization->can($user, 'messages.mention_all') || $this->canManage($user, $chat));
    }

    public function forget(): void
    {
        $this->readable = [];
        $this->memberships = [];
    }
}
