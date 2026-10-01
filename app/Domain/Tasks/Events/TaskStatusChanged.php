<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Events;

use App\Domain\Tasks\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain event of every status transition (ТЗ §25, §44): consumers (notifications, reporting, automation)
 * learn about it here instead of reaching into the Tasks module.
 */
final class TaskStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Task $task,
        public readonly string $from,
        public readonly string $to,
        public readonly int $actorUserId,
    ) {}
}
