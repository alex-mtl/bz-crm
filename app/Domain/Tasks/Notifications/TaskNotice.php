<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Notifications;

use App\Domain\Tasks\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In-app notices about tasks: escalation of blocked / overdue tasks (ФО §6.8.3) and "reassign" hints
 * for tasks of a deactivated user (Д-15). Stored in the format Filament's database notifications read,
 * translated when shown — the text never contains data the recipient could not see.
 */
final class TaskNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const string ESCALATED = 'escalated';

    public const string REASSIGN = 'reassign';

    public function __construct(public readonly string $kind, public readonly Task $task) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return self::payload($this->kind, $this->task);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(string $kind, Task $task): array
    {
        return [
            'format' => 'filament',
            'title' => __('tasks.notices.'.$kind, ['title' => $task->title]),
            'body' => null,
            'icon' => 'heroicon-o-clipboard-document-check',
            'iconColor' => $kind === self::ESCALATED ? 'danger' : 'warning',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'open',
                'label' => __('tasks.notices.open'),
                'url' => '/admin/tasks/'.$task->id,
                'shouldMarkAsRead' => true,
            ]],
            'task_id' => $task->id,
            'kind' => $kind,
        ];
    }
}
