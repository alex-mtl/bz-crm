<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Messaging\Actions\ManageChats;
use App\Domain\Messaging\Models\ChatInvitation;
use App\Filament\Concerns\ChecksPermissions;
use Filament\Pages\Page;

/**
 * Joining a group chat by an invitation link (ФО §6.6.2). Opening the link changes nothing: the person confirms.
 */
class MessengerJoin extends Page
{
    use ChecksPermissions;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'messenger/join/{token}';

    protected string $view = 'filament.pages.messenger-join';

    public string $token = '';

    public static function canAccess(): bool
    {
        return static::allows('chats.write');
    }

    public function getTitle(): string
    {
        return __('messaging.ui.join_by_link');
    }

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function getInvitationProperty(): ?ChatInvitation
    {
        $invitation = ChatInvitation::query()->with('chat')->where('token_hash', ChatInvitation::hashToken($this->token))->first();

        return $invitation !== null && $invitation->isUsable() && $invitation->chat->archived_at === null ? $invitation : null;
    }

    public function join(): void
    {
        $chat = null;
        static::attempt(function () use (&$chat): void {
            $chat = app(ManageChats::class)->joinByLink(static::actor(), $this->token);
        });
        if ($chat !== null) {
            $this->redirect(Messenger::getUrl(['chat' => $chat->id]));
        }
    }
}
