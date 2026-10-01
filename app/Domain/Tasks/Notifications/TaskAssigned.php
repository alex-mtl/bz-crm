<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Notifications;

use App\Domain\Notifications\Concerns\RoutesByPreference;
use App\Domain\Tasks\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In-app notice about a new assignment (ФО §6.8.3). The full notification centre comes in phase 5.
 */
final class TaskAssigned extends Notification implements ShouldQueue
{
    use Queueable;
    use RoutesByPreference;

    public function __construct(public readonly Task $task) {}

    public function category(): string
    {
        return 'tasks';
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return TaskNotice::payload('assigned', $this->task);
    }
}
