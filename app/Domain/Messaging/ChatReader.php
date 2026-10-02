<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Exceptions\MessagingRuleViolation;
use App\Domain\Messaging\Models\Chat;
use App\Domain\Messaging\Models\ChatMember;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessagePollVote;
use App\Domain\Messaging\Models\MessageReaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * What a person reads in the messenger (ФО §6.6): their chats, a chat as a tree of threads, the search.
 * Every query starts from ChatAccess, so nothing from a chat the reader is not part of can appear — not in a
 * list, not in a search, not through a forwarded message.
 */
final readonly class ChatReader
{
    public function __construct(
        private ChatAccess $access,
        private ChatSubjects $subjects,
        private AuthorizationService $authorization,
        private EventJournal $journal,
    ) {}

    /**
     * The chats of the reader, the most recent first, each with its title, the number of unread messages and
     * the reader's own membership.
     *
     * @return list<array{chat: Chat, title: string, unread: int, member: ChatMember|null}>
     */
    public function chats(User $user, ?string $search = null): array
    {
        $chats = $this->access->visible($user)->orderByDesc('last_message_at')->orderByDesc('id')->get();
        $members = ChatMember::query()->where('person_id', $user->person_id)->whereIn('chat_id', $chats->pluck('id'))->get()->keyBy('chat_id');
        $unread = $this->unreadCounts($user);
        $rows = [];
        foreach ($chats as $chat) {
            $title = $this->title($user, $chat);
            if (filled($search) && ! str_contains(mb_strtolower($title), mb_strtolower(trim((string) $search)))) {
                continue;
            }
            $rows[] = ['chat' => $chat, 'title' => $title, 'unread' => $unread[$chat->id] ?? 0, 'member' => $members->get($chat->id)];
        }

        return $rows;
    }

    /**
     * How the reader calls the chat: the other person of a dialog, the title of a group chat, the object discussed.
     */
    public function title(User $viewer, Chat $chat): string
    {
        if ($chat->isDirect()) {
            $other = ChatMember::query()->with('person')->where('chat_id', $chat->id)->where('person_id', '!=', $viewer->person_id)->first();

            return $other?->person->fullName() ?? __('messaging.ui.dialog');
        }
        if ($chat->isSubject()) {
            return $this->subjects->title($chat) ?? __('messaging.ui.discussion');
        }

        return $chat->title ?? __('messaging.ui.chat');
    }

    /**
     * @return array<int, int> chat id => unread messages of other people
     */
    public function unreadCounts(User $user): array
    {
        $ids = $this->access->chatIdsFor($user);
        if ($ids === []) {
            return [];
        }

        return Message::query()->join('chat_members as rm', fn ($join) => $join->on('rm.chat_id', '=', 'messages.chat_id')->where('rm.person_id', $user->person_id))
            ->whereIn('messages.chat_id', $ids)->where('messages.status', Message::SENT)->whereNull('messages.deleted_at')
            ->where('messages.author_person_id', '!=', $user->person_id)
            ->where(fn (Builder $where) => $where->whereNull('rm.last_read_message_id')->orWhereColumn('messages.id', '>', 'rm.last_read_message_id'))
            ->selectRaw('messages.chat_id as chat_id, count(*) as total')->groupBy('messages.chat_id')
            ->pluck('total', 'chat_id')->map(fn ($count): int => (int) $count)->all();
    }

    public function unreadTotal(User $user): int
    {
        return array_sum($this->unreadCounts($user));
    }

    /**
     * The chat as the reader sees it: the last threads (a thread is a top-level message with everything under
     * it), in tree order. The reader's own scheduled messages are there too — nobody else sees them.
     *
     * @return Collection<int, Message>
     */
    public function messages(User $viewer, Chat $chat, int $threads = 30, ?int $aroundMessageId = null): Collection
    {
        if (! $this->access->mayRead($viewer, $chat)) {
            throw new AuthorizationException(__('access.denied'));
        }
        $visible = fn (): Builder => Message::query()->where('chat_id', $chat->id)->where(fn (Builder $where) => $where
            ->where('status', Message::SENT)
            ->orWhere(fn (Builder $own) => $own->where('status', Message::SCHEDULED)->where('author_person_id', $viewer->person_id)));

        $roots = $visible()->whereNull('parent_id')->orderByDesc('id')->limit($threads)->pluck('id');
        if ($aroundMessageId !== null) {
            // A link to a message opens its thread even when it is older than the last ones.
            $target = $visible()->whereKey($aroundMessageId)->first();
            if ($target !== null) {
                $roots->push($target->root_id ?? $target->id);
            }
        }
        $roots = $roots->unique()->sort()->values();

        $all = $visible()->with(['author', 'attachments', 'pollOptions', 'quoted.author', 'forwardedFrom.author', 'forwardedFrom.attachments', 'forwardedFrom.chat', 'mentioned'])
            ->where(fn (Builder $where) => $where->whereIn('id', $roots)->orWhereIn('root_id', $roots))->orderBy('id')->get();

        return $this->inTreeOrder($all, $roots->all());
    }

    /**
     * One thread: the root and everything under it.
     *
     * @return Collection<int, Message>
     */
    public function thread(User $viewer, Message $message): Collection
    {
        if (! $this->access->mayRead($viewer, $message->chat)) {
            throw new AuthorizationException(__('access.denied'));
        }
        $rootId = $message->root_id ?? $message->id;
        $all = Message::query()->with('author')->where('chat_id', $message->chat_id)->where('status', Message::SENT)
            ->where(fn (Builder $where) => $where->whereKey($rootId)->orWhere('root_id', $rootId))->orderBy('id')->get();

        return $this->inTreeOrder($all, [$rootId]);
    }

    /**
     * The thread as text — the context a task made from it starts with (ФО §6.6.3).
     */
    public function threadDigest(User $viewer, Message $message, int $limit = 20): string
    {
        $lines = [];
        foreach ($this->thread($viewer, $message)->take($limit) as $item) {
            if ($item->isDeleted() || $item->kind === Message::SYSTEM || blank($item->body)) {
                continue;
            }
            $lines[] = str_repeat('  ', $item->depth).$item->author->fullName().': '.$item->body;
        }

        return implode("\n", $lines);
    }

    /**
     * May the reader see the message this one forwards? Only if they may read the chat it came from.
     */
    public function seesOriginal(User $viewer, Message $forward): bool
    {
        $original = $forward->forwardedFrom;

        return $original !== null && ! $original->isDeleted() && $this->access->mayRead($viewer, $original->chat);
    }

    /**
     * @return Collection<int, Message>
     */
    public function pinned(User $viewer, Chat $chat): Collection
    {
        if (! $this->access->mayRead($viewer, $chat)) {
            return new Collection;
        }

        return Message::query()->with('author')->where('chat_id', $chat->id)->where('status', Message::SENT)
            ->whereNotNull('pinned_at')->whereNull('deleted_at')->orderByDesc('pinned_at')->get();
    }

    /**
     * Search over the texts of messages (ФО §6.6.4) — inside the chats the reader may read, and nowhere else.
     * A forwarded message has no text of its own, so it cannot leak the original through the search either.
     *
     * @return Builder<Message>
     */
    public function search(User $viewer, string $term, ?Chat $chat = null): Builder
    {
        $term = trim($term);
        $ids = $this->access->chatIdsFor($viewer);
        if ($chat !== null) {
            $ids = $this->access->mayRead($viewer, $chat) ? [$chat->id] : [];
        }
        $query = Message::query()->with(['author', 'chat'])->whereIn('chat_id', $ids)->where('status', Message::SENT)->whereNull('deleted_at');
        if ($term === '' || $ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('body', 'like', '%'.addcslashes($term, '%_\\').'%')->orderByDesc('id');
    }

    /**
     * "Отправлено / доставлено / прочитано" (ФО §6.6.1) of the reader's own message in a dialog.
     */
    public function deliveryStatus(Chat $chat, Message $message): ?string
    {
        if (! $chat->isDirect() || ! $message->isSent()) {
            return null;
        }
        $other = ChatMember::query()->where('chat_id', $chat->id)->where('person_id', '!=', $message->author_person_id)->first();

        return match (true) {
            $other === null => null,
            (int) $other->last_read_message_id >= $message->id => 'read',
            (int) $other->last_delivered_message_id >= $message->id => 'delivered',
            default => 'sent',
        };
    }

    /**
     * Reactions and poll results of the given messages: counts and the reader's own choice.
     *
     * @param  Collection<int, Message>  $messages
     * @return array{reactions: array<int, array<string, int>>, mine: array<int, string>, votes: array<int, int>, my_votes: array<int, int>}
     */
    public function decorations(User $viewer, Collection $messages): array
    {
        $ids = $messages->pluck('id')->all();
        $result = ['reactions' => [], 'mine' => [], 'votes' => [], 'my_votes' => []];
        if ($ids === []) {
            return $result;
        }
        foreach (MessageReaction::query()->whereIn('message_id', $ids)->get() as $reaction) {
            $result['reactions'][$reaction->message_id][$reaction->reaction_code] = ($result['reactions'][$reaction->message_id][$reaction->reaction_code] ?? 0) + 1;
            if ($reaction->person_id === $viewer->person_id) {
                $result['mine'][$reaction->message_id] = $reaction->reaction_code;
            }
        }
        foreach (MessagePollVote::query()->whereIn('message_id', $ids)->get() as $vote) {
            $result['votes'][$vote->option_id] = ($result['votes'][$vote->option_id] ?? 0) + 1;
            if ($vote->person_id === $viewer->person_id) {
                $result['my_votes'][$vote->message_id] = $vote->option_id;
            }
        }

        return $result;
    }

    /**
     * Reading a chat without being in it — for an investigation only (каталог §8, 🔒). A reason is required and
     * every such reading goes to the journal.
     *
     * @return Collection<int, Message>
     */
    public function investigate(User $actor, Chat $chat, string $reason): Collection
    {
        $this->authorization->authorize($actor, 'chats.read.investigation');
        $reason = trim($reason);
        if ($reason === '') {
            throw MessagingRuleViolation::because('reason_required');
        }
        $this->journal->record('messaging.chat.investigated', $chat, [], ['reason' => $reason]);

        return Message::query()->with('author')->where('chat_id', $chat->id)->where('status', Message::SENT)->orderBy('id')->get();
    }

    /**
     * @param  EloquentCollection<int, Message>  $messages
     * @param  list<int>  $rootIds
     * @return Collection<int, Message>
     */
    private function inTreeOrder(EloquentCollection $messages, array $rootIds): Collection
    {
        $byParent = $messages->groupBy(fn (Message $message): int => $message->parent_id ?? 0);
        $byId = $messages->keyBy('id');
        $ordered = new Collection;
        $walk = function (int $parentId) use (&$walk, $byParent, $ordered): void {
            foreach ($byParent->get($parentId, []) as $message) {
                $ordered->push($message);
                $walk($message->id);
            }
        };
        foreach ($rootIds as $rootId) {
            $root = $byId->get($rootId);
            if ($root !== null) {
                $ordered->push($root);
                $walk($rootId);
            }
        }

        return $ordered;
    }
}
