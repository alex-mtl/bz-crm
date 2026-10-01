<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Messaging\Models\Chat;
use App\Domain\Messaging\Models\ChatMember;
use App\Domain\Messaging\Models\Message;
use Illuminate\Database\Eloquent\Model;

/**
 * Discussion threads attached to objects. Access is decided by the owning module (a task's chat follows
 * the task's rights): this service only stores and lists.
 */
final class Discussions
{
    public function forSubject(Model $subject): Chat
    {
        return Chat::query()->firstOrCreate(['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey()]);
    }

    public function findFor(Model $subject): ?Chat
    {
        return Chat::query()->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->first();
    }

    /**
     * @param  list<int>  $personIds
     */
    public function syncMembers(Chat $chat, array $personIds): void
    {
        $existing = ChatMember::query()->where('chat_id', $chat->id)->pluck('person_id')->map(fn ($id): int => (int) $id)->all();
        foreach (array_diff(array_unique(array_map('intval', $personIds)), $existing) as $personId) {
            ChatMember::query()->create(['chat_id' => $chat->id, 'person_id' => $personId, 'joined_at' => now()]);
        }
    }

    public function post(Chat $chat, int $authorPersonId, string $body): Message
    {
        $this->syncMembers($chat, [$authorPersonId]);

        return Message::query()->create(['chat_id' => $chat->id, 'author_person_id' => $authorPersonId, 'body' => trim($body)]);
    }
}
