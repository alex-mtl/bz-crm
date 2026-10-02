<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Discussions;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Domain\Tasks\Models\Task;
use App\Filament\Pages\Messenger;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The task's discussion thread (ФО §6.8.2): whoever may read the task reads and writes here.
 */
final class TaskDiscussion extends Component
{
    public int $taskId;

    public string $body = '';

    public ?string $error = null;

    private function task(): Task
    {
        $user = Filament::auth()->user();
        $task = Task::query()->findOrFail($this->taskId);
        abort_unless($user instanceof User && app(AuthorizationService::class)->can($user, 'tasks.read', $task), 403);

        return $task;
    }

    public function post(): void
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);
        try {
            app(ManageTasks::class)->post($user, $this->task(), $this->body);
            $this->body = '';
            $this->error = null;
        } catch (AuthorizationException|DomainException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $chat = app(Discussions::class)->findFor($this->task());
        /** @var Collection<int, Message> $messages */
        $messages = $chat !== null ? $chat->messages()->with('author')->where('status', Message::SENT)->get() : collect();

        return view('livewire.task-discussion', ['messages' => $messages, 'messengerUrl' => $chat !== null ? Messenger::getUrl(['chat' => $chat->id]) : null]);
    }
}
