<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Console;

use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\OrgStructure;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskPerson;
use App\Domain\Tasks\Notifications\TaskNotice;
use App\Support\Settings\SystemSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ФО §6.8.3: a task blocked, or overdue, for N days (a setting, 3 by default) is escalated to the direct managers
 * of its assignees. Once per task until it changes status again.
 */
final class EscalateTasksCommand extends Command
{
    public const string DAYS_KEY = 'tasks.escalation_days';

    protected $signature = 'tasks:escalate';

    protected $description = 'Escalate blocked and overdue tasks to the managers of their assignees';

    public function handle(OrgStructure $org, SystemSettings $settings, EventJournal $journal): int
    {
        $days = max(1, (int) $settings->get(self::DAYS_KEY, 3));
        $threshold = now()->subDays($days);

        $tasks = Task::query()->notDeleted()->whereNull('escalated_at')
            ->where(fn ($q) => $q->where(fn ($b) => $b->where('status_code', 'blocked')->where('updated_at', '<=', $threshold))
                ->orWhere(fn ($o) => $o->overdue()->where('due_at', '<=', $threshold)))
            ->get();

        foreach ($tasks as $task) {
            $assignees = TaskPerson::query()->where('task_id', $task->id)->where('role', TaskPerson::ASSIGNEE)->pluck('person_id');
            $managers = $assignees->map(fn ($id) => $org->membership((int) $id)?->manager_person_id)->filter()->unique()->values();
            DB::transaction(function () use ($task, $managers, $journal): void {
                $task->forceFill(['escalated_at' => now()])->saveQuietly();
                $journal->record('tasks.task.escalated', $task, [], ['status' => $task->status_code, 'managers' => $managers->all()]);
            });
            foreach (User::query()->whereIn('person_id', $managers)->get() as $manager) {
                $manager->notify(new TaskNotice(TaskNotice::ESCALATED, $task));
            }
        }

        $this->info("Escalated {$tasks->count()} task(s).");

        return self::SUCCESS;
    }
}
