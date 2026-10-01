<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Listeners;

use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Events\UserDeactivated;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\OrgStructure;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskPerson;
use App\Domain\Tasks\Notifications\TaskNotice;

/**
 * Д-15 (our default): open tasks of a deactivated user are not reassigned automatically; they keep their history,
 * and the creator and the user's direct manager get a "reassign" hint.
 */
final readonly class SuggestReassignment
{
    public function __construct(private OrgStructure $org, private EventJournal $journal) {}

    public function handle(UserDeactivated $event): void
    {
        $personId = $event->user->person_id;
        $manager = $this->org->membership($personId)?->manager_person_id;
        $taskIds = TaskPerson::query()->where('person_id', $personId)->where('role', TaskPerson::ASSIGNEE)->pluck('task_id');

        foreach (Task::query()->notDeleted()->whereKey($taskIds)->whereNotIn('status_code', Task::CLOSED)->get() as $task) {
            $recipients = array_values(array_unique(array_filter([$task->creator_person_id, $manager], fn ($id) => $id !== null && $id !== $personId)));
            $this->journal->record('tasks.reassignment_suggested', $task, [], ['deactivated_person_id' => $personId, 'notified' => $recipients]);
            foreach (User::query()->whereIn('person_id', $recipients)->get() as $user) {
                $user->notify(new TaskNotice(TaskNotice::REASSIGN, $task));
            }
        }
    }
}
