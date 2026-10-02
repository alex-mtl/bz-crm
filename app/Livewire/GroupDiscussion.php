<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domain\Groups\Actions\ManageGroups;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\Group;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Discussions;
use App\Filament\Pages\Messenger;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The chat of a group (ФО §6.5): its members read and write here; for anyone else the component does not exist.
 */
final class GroupDiscussion extends Component
{
    public int $groupId;

    public string $body = '';

    public ?string $error = null;

    private function actor(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function group(): Group
    {
        $group = Group::query()->findOrFail($this->groupId);
        abort_unless(app(GroupAccess::class)->isMember($group, $this->actor()->person_id), 404);

        return $group;
    }

    public function post(): void
    {
        try {
            app(ManageGroups::class)->post($this->actor(), $this->group(), $this->body);
            $this->body = '';
            $this->error = null;
        } catch (AuthorizationException|DomainException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $group = $this->group();
        $chat = app(Discussions::class)->findFor($group);

        return view('livewire.task-discussion', [
            'messages' => app(ManageGroups::class)->messages($this->actor(), $group),
            'messengerUrl' => $chat !== null ? Messenger::getUrl(['chat' => $chat->id]) : null,
        ]);
    }
}
