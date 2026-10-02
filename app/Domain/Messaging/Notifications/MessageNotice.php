<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Notifications;

use App\Domain\Identity\Models\User;
use App\Domain\Messaging\ChatAccess;
use App\Domain\Messaging\Models\Chat;
use App\Domain\Notifications\Concerns\RoutesByPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * "There is something new in a chat" or "you were mentioned". The notice names the chat and the sender — never
 * the text of the message: the text is read in the chat, where access is checked.
 */
final class MessageNotice extends Notification implements ShouldQueue
{
    use Queueable;
    use RoutesByPreference;

    public const string MESSAGE = 'message';

    public const string MENTION = 'mention';

    public function __construct(
        public readonly string $kind,
        public readonly int $chatId,
        public readonly string $chatTitle,
        public readonly string $senderName,
        public readonly ?int $messageId = null,
    ) {}

    public function category(): string
    {
        return $this->kind === self::MENTION ? 'mentions' : 'messages';
    }

    /**
     * Checked at delivery: whoever has been removed from the chat meanwhile is told nothing (ТЗ §37).
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User) {
            return true;
        }
        $chat = Chat::query()->find($this->chatId);

        return $chat !== null && app(ChatAccess::class)->mayRead($notifiable, $chat);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'format' => 'filament',
            'title' => __('messaging.notices.'.$this->kind, ['chat' => $this->chatTitle, 'name' => $this->senderName]),
            'body' => null,
            'icon' => $this->kind === self::MENTION ? 'heroicon-o-at-symbol' : 'heroicon-o-chat-bubble-left-right',
            'iconColor' => $this->kind === self::MENTION ? 'warning' : 'info',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'open',
                'label' => __('messaging.notices.open'),
                'url' => '/admin/messenger?chat='.$this->chatId.($this->messageId !== null ? '&message='.$this->messageId : ''),
                'shouldMarkAsRead' => true,
            ]],
            'kind' => 'chat_'.$this->kind,
            'chat_id' => $this->chatId,
            'subject' => ['chat', $this->chatId],
        ];
    }
}
