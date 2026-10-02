<?php

use App\Domain\Identity\Models\User;
use App\Domain\Messaging\ChatAccess;
use App\Domain\Messaging\Models\Chat;
use Illuminate\Support\Facades\Broadcast;

/*
 * WebSocket channels of the messenger (ADR-012). A channel only says "something changed" — the content is then
 * read through the server. Still, even the fact is told only to those who may read the chat.
 */

Broadcast::channel('chat.{chatId}', function (User $user, int $chatId): bool {
    $chat = Chat::query()->find($chatId);

    return $chat !== null && app(ChatAccess::class)->mayRead($user, $chat);
});

Broadcast::channel('messenger.{userId}', fn (User $user, int $userId): bool => $user->id === $userId);
