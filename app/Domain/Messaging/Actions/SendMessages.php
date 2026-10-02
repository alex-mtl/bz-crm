<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\ChatAccess;
use App\Domain\Messaging\ChatReader;
use App\Domain\Messaging\Events\ChatUpdated;
use App\Domain\Messaging\Exceptions\MessagingRuleViolation;
use App\Domain\Messaging\Jobs\ScanMessageAttachment;
use App\Domain\Messaging\Models\Chat;
use App\Domain\Messaging\Models\ChatMember;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageAttachment;
use App\Domain\Messaging\Models\MessagePollOption;
use App\Domain\Messaging\Models\MessagePollVote;
use App\Domain\Messaging\Models\MessageReaction;
use App\Domain\Messaging\Notifications\MessageNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Messages (ФО §6.6): sending — now or later, as a reply inside a thread, with a quotation, mentions, a poll and
 * files; editing, deleting with a mark, forwarding, reactions, pins, drafts, the read state.
 *
 * A message is not journaled: the chat itself is the record. What is journaled is what changes somebody else's
 * words or the rules — a deletion by a moderator, an infected file, a change of the policy.
 */
final readonly class SendMessages
{
    public function __construct(
        private AuthorizationService $authorization,
        private ChatAccess $access,
        private ManageChats $chats,
        private ChatReader $reader,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array{body?: string|null, parent_id?: int|null, quoted_message_id?: int|null, mention_person_ids?: list<int>,
     *               mention_all?: bool, poll_options?: list<string>, send_at?: Carbon|string|null}  $data
     * @param  list<array{source: string, name: string, mime?: string|null}>  $files  files already on the server (uploads)
     */
    public function send(User $actor, Chat $chat, array $data, array $files = []): Message
    {
        $this->ensureMayWrite($actor, $chat);
        $body = filled($data['body'] ?? null) ? trim((string) $data['body']) : null;
        $pollOptions = array_values(array_filter(array_map(fn ($option): string => trim((string) $option), $data['poll_options'] ?? [])));
        if ($body === null && $files === [] && $pollOptions === []) {
            throw MessagingRuleViolation::because('empty_message');
        }
        if (count($pollOptions) === 1 || ($pollOptions !== [] && $body === null)) {
            throw MessagingRuleViolation::because('poll_needs_question');
        }
        if (count($files) > (int) config('messaging.max_attachments')) {
            throw MessagingRuleViolation::because('too_many_files', ['limit' => (int) config('messaging.max_attachments')]);
        }
        $parent = $this->sibling($chat, $data['parent_id'] ?? null);
        $quoted = $this->sibling($chat, $data['quoted_message_id'] ?? null);

        $mentionAll = (bool) ($data['mention_all'] ?? false);
        if ($mentionAll && ! $this->access->mayMentionAll($actor, $chat)) {
            throw new AuthorizationException(__('messaging.errors.mention_all_not_allowed'));
        }
        // Only people who are in the chat can be mentioned: a mention must not pull an outsider's attention to it.
        $mentioned = ChatMember::query()->where('chat_id', $chat->id)
            ->whereIn('person_id', array_map('intval', $data['mention_person_ids'] ?? []))->where('person_id', '!=', $actor->person_id)
            ->pluck('person_id')->map(fn ($id): int => (int) $id)->all();

        $sendAt = isset($data['send_at']) && filled($data['send_at']) ? Carbon::parse($data['send_at']) : null;
        if ($sendAt !== null && ! $sendAt->isFuture()) {
            throw MessagingRuleViolation::because('send_in_past');
        }

        $message = DB::transaction(function () use ($actor, $chat, $body, $parent, $quoted, $mentionAll, $mentioned, $pollOptions, $files, $sendAt): Message {
            $member = $this->chats->ensureMember($chat, $actor->person_id);
            $maxDepth = (int) config('messaging.max_thread_depth');
            $message = Message::query()->create([
                'chat_id' => $chat->id,
                'author_person_id' => $actor->person_id,
                'body' => $body,
                'parent_id' => $parent?->id,
                'root_id' => $parent !== null ? ($parent->root_id ?? $parent->id) : null,
                // The tree is kept readable: beyond the limit replies stay on the last level.
                'depth' => $parent !== null ? min($parent->depth + 1, $maxDepth) : 0,
                'quoted_message_id' => $quoted?->id,
                'kind' => $pollOptions !== [] ? Message::POLL : Message::TEXT,
                'status' => $sendAt !== null ? Message::SCHEDULED : Message::SENT,
                'send_at' => $sendAt,
                'mentions_all' => $mentionAll,
            ]);
            $message->mentioned()->sync($mentioned);
            foreach ($pollOptions as $index => $text) {
                MessagePollOption::query()->create(['message_id' => $message->id, 'text' => mb_substr($text, 0, 255), 'sort_order' => $index]);
            }
            foreach ($files as $file) {
                $this->attach($message, $file);
            }
            if ($message->isSent()) {
                $chat->update(['last_message_at' => now()]);
                $member->update(['last_read_message_id' => $message->id, 'last_delivered_message_id' => $message->id, 'draft' => null]);
            } else {
                $member->update(['draft' => null]);
            }

            return $message;
        });

        foreach ($message->attachments as $attachment) {
            ScanMessageAttachment::dispatch($attachment->id);
        }
        if ($message->isSent()) {
            $this->deliver($actor, $chat, $message);
        }

        return $message;
    }

    /**
     * Only the author changes their words; the message is marked as edited.
     */
    public function edit(User $actor, Message $message, string $body): Message
    {
        if ($message->author_person_id !== $actor->person_id || $message->isDeleted() || $message->isForward() || $message->kind === Message::SYSTEM) {
            throw new AuthorizationException(__('access.denied'));
        }
        $this->ensureMayWrite($actor, $message->chat);
        $body = trim($body);
        if ($body === '') {
            throw MessagingRuleViolation::because('empty_message');
        }
        if ($body !== $message->body) {
            $message->update(['body' => $body, 'edited_at' => $message->isSent() ? now() : null]);
            $this->changed($message->chat, 'message', $message->id);
        }

        return $message;
    }

    /**
     * Deletion leaves a mark in the thread (ФО §6.6.4). The author deletes their own message; a moderator of the
     * chat — anybody's, and that is journaled.
     */
    public function delete(User $actor, Message $message): void
    {
        $chat = $message->chat;
        $own = $message->author_person_id === $actor->person_id;
        if (! $this->access->mayRead($actor, $chat) || (! $own && ! $this->access->canModerate($actor, $chat))) {
            throw new AuthorizationException(__('access.denied'));
        }
        if ($message->isDeleted()) {
            return;
        }
        // A message that was never sent leaves no mark: it is simply withdrawn, files included.
        if ($message->status === Message::SCHEDULED) {
            Storage::disk('local')->delete($message->attachments()->pluck('path')->all());
            $message->delete();

            return;
        }

        DB::transaction(function () use ($actor, $message, $chat, $own): void {
            $message->update(['deleted_at' => now(), 'deleted_by_user_id' => $actor->id, 'pinned_at' => null]);
            if (! $own) {
                $this->journal->record('messaging.message.deleted', $chat, [], ['message_id' => $message->id, 'author_person_id' => $message->author_person_id]);
            }
        });
        $this->changed($chat, 'message', $message->id);
    }

    /**
     * Forwards a message to another chat (ФО §6.6.4). The new message carries no copy of the text: it points at
     * the original, and each reader of the target chat sees it only if they may read the chat it came from.
     */
    public function forward(User $actor, Message $message, Chat $target, ?string $comment = null): Message
    {
        $this->authorization->authorize($actor, 'messages.forward');
        $original = $message->forwardedFrom ?? $message;
        if (! $this->access->mayRead($actor, $message->chat) || ! $this->access->mayRead($actor, $original->chat)
            || ! $original->isSent() || $original->isDeleted() || $original->kind === Message::SYSTEM) {
            throw new AuthorizationException(__('access.denied'));
        }
        $this->ensureMayWrite($actor, $target);

        $forwarded = DB::transaction(function () use ($actor, $target, $original, $comment): Message {
            $member = $this->chats->ensureMember($target, $actor->person_id);
            $forwarded = Message::query()->create([
                'chat_id' => $target->id, 'author_person_id' => $actor->person_id,
                'body' => filled($comment) ? trim((string) $comment) : null,
                'forwarded_from_message_id' => $original->id,
            ]);
            $target->update(['last_message_at' => now()]);
            $member->update(['last_read_message_id' => $forwarded->id, 'last_delivered_message_id' => $forwarded->id]);

            return $forwarded;
        });
        $this->deliver($actor, $target, $forwarded);

        return $forwarded;
    }

    /**
     * Sets, changes or — when the same reaction is given again — removes the reaction of the person.
     */
    public function react(User $actor, Message $message, string $reactionCode): ?MessageReaction
    {
        if (! $this->access->mayRead($actor, $message->chat) || ! $message->isSent() || $message->isDeleted()) {
            throw new AuthorizationException(__('access.denied'));
        }
        if (! CatalogItem::query()->ofCatalog('reaction_types')->selectable()->where('code', $reactionCode)->exists()) {
            throw MessagingRuleViolation::because('invalid_reaction');
        }
        $key = ['message_id' => $message->id, 'person_id' => $actor->person_id];
        $existing = MessageReaction::query()->where($key)->first();
        if ($existing !== null && $existing->reaction_code === $reactionCode) {
            $existing->delete();
            $reaction = null;
        } else {
            $reaction = MessageReaction::query()->updateOrCreate($key, ['reaction_code' => $reactionCode]);
        }
        $this->changed($message->chat, 'reaction', $message->id);

        return $reaction;
    }

    public function vote(User $actor, Message $poll, int $optionId): void
    {
        $this->ensureMayWrite($actor, $poll->chat);
        if ($poll->kind !== Message::POLL || $poll->isDeleted() || ! $poll->pollOptions()->whereKey($optionId)->exists()) {
            throw MessagingRuleViolation::because('invalid_poll_option');
        }
        MessagePollVote::query()->updateOrCreate(['message_id' => $poll->id, 'person_id' => $actor->person_id], ['option_id' => $optionId]);
        $this->changed($poll->chat, 'poll', $poll->id);
    }

    public function pin(User $actor, Message $message, bool $pinned = true): void
    {
        if (! $this->access->mayPin($actor, $message->chat) || ! $message->isSent() || $message->isDeleted()) {
            throw new AuthorizationException(__('access.denied'));
        }
        $message->update(['pinned_at' => $pinned ? now() : null, 'pinned_by_person_id' => $pinned ? $actor->person_id : null]);
        $this->changed($message->chat, 'pin', $message->id);
    }

    /**
     * The text a person has typed and not sent — kept per chat, on the server, so it is there on any device.
     */
    public function saveDraft(User $actor, Chat $chat, ?string $text): void
    {
        $this->ensureMayWrite($actor, $chat);
        $this->chats->ensureMember($chat, $actor->person_id)->update(['draft' => filled($text) ? (string) $text : null]);
        $this->access->forget();
    }

    /**
     * The reader has seen the chat up to its last message.
     */
    public function markRead(User $actor, Chat $chat): void
    {
        if (! $this->access->mayRead($actor, $chat)) {
            throw new AuthorizationException(__('access.denied'));
        }
        $last = Message::query()->where('chat_id', $chat->id)->where('status', Message::SENT)->max('id');
        $member = $this->access->membership($chat, $actor->person_id);
        if ($last === null || $member === null || (int) $member->last_read_message_id >= (int) $last) {
            return;
        }
        $member->update(['last_read_message_id' => $last, 'last_delivered_message_id' => $last]);
        $this->access->forget();
        $actor->unreadNotifications()->where('subject_type', 'chat')->where('subject_id', $chat->id)->update(['read_at' => now()]);
        $this->changed($chat, 'read');
    }

    /**
     * "Delivered" (ФО §6.6.1): the recipient's messenger has received the messages, though the chat is not opened yet.
     */
    public function markDelivered(User $actor): void
    {
        $latest = Message::query()->whereIn('chat_id', $this->access->chatIdsFor($actor))->where('status', Message::SENT)
            ->selectRaw('chat_id, max(id) as last_id')->groupBy('chat_id')->pluck('last_id', 'chat_id');
        foreach ($latest as $chatId => $lastId) {
            ChatMember::query()->where('chat_id', $chatId)->where('person_id', $actor->person_id)
                ->where(fn ($where) => $where->whereNull('last_delivered_message_id')->orWhere('last_delivered_message_id', '<', $lastId))
                ->update(['last_delivered_message_id' => $lastId]);
        }
    }

    /**
     * Scheduled messages whose time has come (scheduler). The message is written anew, so it takes its place in
     * the chat by the moment it is sent, not by the moment it was typed.
     */
    public function sendScheduled(): int
    {
        $sent = 0;
        foreach (Message::query()->where('status', Message::SCHEDULED)->where('send_at', '<=', now())->orderBy('send_at')->get() as $scheduled) {
            $author = User::query()->where('person_id', $scheduled->author_person_id)->where('status', UserStatus::Active)->first();
            $chat = $scheduled->chat;
            // The author has lost the chat or the account since: the message is not sent on their behalf.
            if ($author === null || ! $this->access->mayWrite($author, $chat)) {
                $scheduled->delete();

                continue;
            }
            $message = DB::transaction(function () use ($scheduled, $chat): Message {
                $message = $scheduled->replicate(['status', 'send_at']);
                $message->forceFill(['status' => Message::SENT, 'send_at' => null])->save();
                foreach (['message_attachments', 'message_poll_options', 'message_mentions'] as $table) {
                    DB::table($table)->where('message_id', $scheduled->id)->update(['message_id' => $message->id]);
                }
                $scheduled->delete();
                $chat->update(['last_message_at' => now()]);
                ChatMember::query()->where('chat_id', $chat->id)->where('person_id', $message->author_person_id)
                    ->update(['last_read_message_id' => $message->id, 'last_delivered_message_id' => $message->id]);

                return $message;
            });
            $this->deliver($author, $chat, $message);
            $sent++;
        }

        return $sent;
    }

    /**
     * "Превратить обсуждение в поручение" (ФО §6.6.3): the thread remembers the task made from it and says so in
     * the chat. The task itself is created by the Tasks module — the caller passes what it has created.
     */
    public function linkTask(User $actor, Message $message, int $taskId, string $taskTitle): Message
    {
        $chat = $message->chat;
        $this->ensureMayWrite($actor, $chat);
        $root = $message->root_id !== null ? Message::query()->findOrFail($message->root_id) : $message;

        $note = DB::transaction(function () use ($actor, $chat, $root, $taskId, $taskTitle): Message {
            $root->update(['task_id' => $taskId]);
            $note = Message::query()->create([
                'chat_id' => $chat->id, 'author_person_id' => $actor->person_id, 'kind' => Message::SYSTEM,
                'body' => __('messaging.system.task_created', ['title' => $taskTitle]),
                'parent_id' => $root->id, 'root_id' => $root->id, 'depth' => 1, 'task_id' => $taskId,
            ]);
            $chat->update(['last_message_at' => now()]);

            return $note;
        });
        $this->changed($chat, 'message', $note->id);

        return $note;
    }

    /**
     * The file of an attachment — for those who may read its chat, once the antivirus let it through (ТЗ §68).
     */
    public function attachmentPath(User $viewer, MessageAttachment $attachment): string
    {
        $message = $attachment->message;
        if (! $this->access->mayRead($viewer, $message->chat) || $message->isDeleted() || ! $attachment->isAvailable()
            || ($message->status === Message::SCHEDULED && $message->author_person_id !== $viewer->person_id)
            || ! Storage::disk('local')->exists($attachment->path)) {
            throw new AuthorizationException(__('access.denied'));
        }

        return Storage::disk('local')->path($attachment->path);
    }

    /**
     * Tells the members: a broadcast for open screens, a notice for those who asked for one (ФО §6.6.4:
     * all messages / mentions only / mute).
     */
    private function deliver(User $actor, Chat $chat, Message $message): void
    {
        $members = ChatMember::query()->where('chat_id', $chat->id)->get()->keyBy('person_id');
        $users = User::query()->where('status', UserStatus::Active)->whereIn('person_id', $members->keys())->get();
        event(new ChatUpdated($chat->id, 'message', $message->id, $users->pluck('id')->map(fn ($id): int => (int) $id)->all()));

        $mentioned = $message->mentioned()->pluck('people.id')->flip();
        $sender = $actor->person->fullName();
        foreach ($users as $user) {
            if ($user->id === $actor->id || ! $this->access->mayRead($user, $chat)) {
                continue;
            }
            $level = $members[$user->person_id]->notify;
            $isMentioned = $message->mentions_all || $mentioned->has($user->person_id);
            if ($level === ChatMember::NOTIFY_MUTE || ($level === ChatMember::NOTIFY_MENTIONS && ! $isMentioned)) {
                continue;
            }
            // One unread notice per chat is enough: the next messages are counted in the chat itself.
            if (! $isMentioned && $user->unreadNotifications()->where('subject_type', 'chat')->where('subject_id', $chat->id)->exists()) {
                continue;
            }
            $user->notify(new MessageNotice(
                $isMentioned ? MessageNotice::MENTION : MessageNotice::MESSAGE, $chat->id, $this->reader->title($user, $chat), $sender, $message->id,
            ));
        }
    }

    private function sibling(Chat $chat, mixed $messageId): ?Message
    {
        if (blank($messageId)) {
            return null;
        }
        $message = Message::query()->find((int) $messageId);
        if ($message === null || $message->chat_id !== $chat->id || ! $message->isSent() || $message->isDeleted()) {
            throw MessagingRuleViolation::because('message_of_other_chat');
        }

        return $message;
    }

    /**
     * @param  array{source: string, name: string, mime?: string|null}  $file
     */
    private function attach(Message $message, array $file): void
    {
        $extension = Str::lower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $path = 'messaging/chats/'.$message->chat_id.'/'.Str::uuid().($extension !== '' ? '.'.$extension : '');
        Storage::disk('local')->put($path, (string) file_get_contents($file['source']));
        $mime = $file['mime'] ?? (Storage::disk('local')->mimeType($path) ?: null);

        MessageAttachment::query()->create([
            'message_id' => $message->id,
            'kind' => match (true) {
                str_starts_with((string) $mime, 'image/') => 'image',
                str_starts_with((string) $mime, 'video/') => 'video',
                str_starts_with((string) $mime, 'audio/') => 'audio',
                default => 'file',
            },
            'path' => $path,
            'original_name' => mb_substr($file['name'], 0, 255),
            'mime' => $mime,
            'size' => Storage::disk('local')->size($path),
        ]);
    }

    private function ensureMayWrite(User $actor, Chat $chat): void
    {
        if (! $this->access->mayWrite($actor, $chat)) {
            throw new AuthorizationException(__('access.denied'));
        }
    }

    private function changed(Chat $chat, string $what, ?int $messageId = null): void
    {
        $userIds = User::query()->whereIn('person_id', ChatMember::query()->where('chat_id', $chat->id)->select('person_id'))->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
        event(new ChatUpdated($chat->id, $what, $messageId, $userIds));
    }
}
