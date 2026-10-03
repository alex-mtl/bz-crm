<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Events;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened in a chat: a message came, changed, was read. The broadcast carries no content — only the
 * fact and the ids; whoever hears it reloads through the server, where access is checked again.
 *
 * It goes to the chat itself (for those who have it open) and to each member (for their list of chats).
 */
final class ChatUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  list<int>  $userIds  accounts of the members of the chat
     */
    public function __construct(
        public readonly int $chatId,
        public readonly string $what,
        public readonly ?int $messageId = null,
        public readonly array $userIds = [],
    ) {}

    /**
     * Tells the open screens. The signal is only a hint — the pages also poll — so a WebSocket server that is down
     * must never undo what has just been done: where the broadcast runs at once (a sync queue), its failure is
     * logged and swallowed.
     *
     * @param  list<int>  $userIds
     */
    public static function tell(int $chatId, string $what, ?int $messageId = null, array $userIds = []): void
    {
        try {
            event(new self($chatId, $what, $messageId, $userIds));
        } catch (BroadcastException $e) {
            report($e);
        }
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.'.$this->chatId),
            ...array_map(fn (int $userId): PrivateChannel => new PrivateChannel('messenger.'.$userId), $this->userIds),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['chat_id' => $this->chatId, 'what' => $this->what, 'message_id' => $this->messageId];
    }
}
