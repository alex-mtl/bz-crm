<?php

declare(strict_types=1);

namespace App\Domain\CRM\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\CRM\Events\AppealStatusChanged;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\Appeal;
use App\Domain\CRM\Notifications\CrmNotice;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Models\Person;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Appeals (ФО §6.9.3): new → in progress → done / rejected, with a responsible and a deadline, linked to the
 * person who applied and to the tasks it produced.
 */
final readonly class ManageAppeals
{
    public function __construct(
        private AuthorizationService $authorization,
        private OrgStructure $org,
        private ManageTasks $tasks,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array{title: string, body?: string|null, type_code: string, source_code?: string|null, person_id?: int|null,
     *               responsible_person_id?: int|null, org_unit_id?: int|null, territory_id?: int|null, due_at?: Carbon|string|null,
     *               priority_code?: string|null}  $data
     */
    public function register(User $actor, array $data): Appeal
    {
        $title = trim($data['title']);
        if ($title === '') {
            throw CrmRuleViolation::because('appeal_title_required');
        }
        $type = CatalogItem::query()->ofCatalog('appeal_types')->selectable()->where('code', $data['type_code'])->first();
        if ($type === null) {
            throw CrmRuleViolation::because('invalid_appeal_type');
        }
        $priority = CatalogItem::query()->ofCatalog('appeal_priorities')->selectable()->where('code', $data['priority_code'] ?? 'normal')->first();
        if ($priority === null) {
            throw CrmRuleViolation::because('invalid_appeal_priority');
        }
        // The priority may shorten the deadline of the resolution; otherwise the type of the appeal sets it.
        $dueDays = (int) $priority->property('due_days', 0) > 0 ? (int) $priority->property('due_days') : (int) $type->property('due_days', 7);
        $person = isset($data['person_id']) ? Person::query()->findOrFail($data['person_id']) : null;

        $appeal = new Appeal([
            'title' => $title,
            'body' => filled($data['body'] ?? null) ? trim((string) $data['body']) : null,
            'type_code' => $type->code,
            'source_code' => $data['source_code'] ?? null,
            'status' => Appeal::NEW,
            'person_id' => $person?->id,
            'org_unit_id' => $data['org_unit_id'] ?? $person->responsible_unit_id ?? $this->org->unitOf($actor->person_id)?->id,
            'territory_id' => $data['territory_id'] ?? $person->territory_id ?? null,
            'priority_code' => $priority->code,
            'first_response_due_at' => now()->addHours(max(1, (int) $priority->property('first_response_hours', 48))),
            'due_at' => isset($data['due_at']) ? Carbon::parse($data['due_at']) : now()->addDays($dueDays),
            'created_by_person_id' => $actor->person_id,
        ]);
        $this->authorization->authorize($actor, 'appeals.create', $appeal);
        if ($person !== null) {
            $this->authorization->authorize($actor, 'people.read', $person);
        }

        $responsibleId = $data['responsible_person_id'] ?? null;
        if ($responsibleId !== null) {
            if ($responsibleId !== $actor->person_id) {
                $this->authorization->authorize($actor, 'appeals.assign', $appeal);
            }
            $this->ensureActiveUser($responsibleId);
            $appeal->responsible_person_id = $responsibleId;
        }

        return DB::transaction(function () use ($actor, $appeal): Appeal {
            // The number is assigned after the row exists, so it is unique without a separate counter.
            $appeal->number = 'TMP-'.bin2hex(random_bytes(6));
            $appeal->save();
            $appeal->update(['number' => sprintf('A-%s-%06d', $appeal->created_at->format('Y'), $appeal->id)]);

            $this->journal->record('crm.appeal.registered', $appeal, [], $appeal->only(['number', 'type_code', 'priority_code', 'person_id', 'responsible_person_id', 'org_unit_id', 'territory_id']));
            AppealStatusChanged::dispatch($appeal, null, Appeal::NEW, $actor->id);
            $this->notifyResponsible($appeal, $actor);

            return $appeal;
        });
    }

    public function assign(User $actor, Appeal $appeal, ?int $responsiblePersonId): Appeal
    {
        $this->authorization->authorize($actor, 'appeals.assign', $appeal);
        if ($responsiblePersonId !== null) {
            $this->ensureActiveUser($responsiblePersonId);
        }
        if ($appeal->responsible_person_id === $responsiblePersonId) {
            return $appeal;
        }

        return DB::transaction(function () use ($actor, $appeal, $responsiblePersonId): Appeal {
            $old = $appeal->responsible_person_id;
            $appeal->update(['responsible_person_id' => $responsiblePersonId]);
            $appeal->unsetRelation('responsible');
            $this->journal->record('crm.appeal.assigned', $appeal, ['responsible_person_id' => $old], ['responsible_person_id' => $responsiblePersonId]);
            $this->notifyResponsible($appeal, $actor);

            return $appeal;
        });
    }

    /**
     * new → in_progress; done / rejected only from work in progress or straight from new; a closed appeal can be
     * returned to work by those who may assign it.
     */
    public function changeStatus(User $actor, Appeal $appeal, string $to, ?string $resolution = null): Appeal
    {
        if (! in_array($to, Appeal::STATUSES, true) || $to === Appeal::NEW || $to === $appeal->status) {
            throw CrmRuleViolation::because('invalid_appeal_transition');
        }
        // Reopening undoes a decision — that takes the right to manage the appeal, not just to work on it.
        $this->authorization->authorize($actor, $appeal->isClosed() ? 'appeals.assign' : 'appeals.close', $appeal);
        if ($appeal->isClosed() && $to !== Appeal::IN_PROGRESS) {
            throw CrmRuleViolation::because('invalid_appeal_transition');
        }
        $resolution = filled($resolution) ? trim((string) $resolution) : null;
        if ($to === Appeal::REJECTED && $resolution === null) {
            throw CrmRuleViolation::because('rejection_needs_reason');
        }

        return DB::transaction(function () use ($actor, $appeal, $to, $resolution): Appeal {
            $from = $appeal->status;
            $closing = in_array($to, [Appeal::DONE, Appeal::REJECTED], true);
            $appeal->update([
                // Leaving "new" for the first time is the first response (Д-22).
                'first_responded_at' => $appeal->first_responded_at ?? ($from === Appeal::NEW ? now() : null),
                'status' => $to,
                'resolution' => $closing ? $resolution : $appeal->resolution,
                'closed_at' => $closing ? now() : null,
            ]);
            $this->journal->record('crm.appeal.status_changed', $appeal, ['status' => $from], ['status' => $to]);
            AppealStatusChanged::dispatch($appeal, $from, $to, $actor->id);

            return $appeal;
        });
    }

    public function prioritize(User $actor, Appeal $appeal, string $priorityCode): Appeal
    {
        $this->authorization->authorize($actor, 'appeals.assign', $appeal);
        if (! CatalogItem::query()->ofCatalog('appeal_priorities')->selectable()->where('code', $priorityCode)->exists()) {
            throw CrmRuleViolation::because('invalid_appeal_priority');
        }
        if ($appeal->priority_code === $priorityCode) {
            return $appeal;
        }

        return DB::transaction(function () use ($appeal, $priorityCode): Appeal {
            $old = $appeal->priority_code;
            $appeal->update(['priority_code' => $priorityCode]);
            $this->journal->record('crm.appeal.prioritized', $appeal, ['priority_code' => $old], ['priority_code' => $priorityCode]);

            return $appeal;
        });
    }

    /**
     * Creates a task for the appeal: the applicant becomes the subject of the task (Д-15).
     *
     * @param  array<string, mixed>  $taskData  title, type_code, due_at, description…
     * @param  list<int>  $assigneeIds
     */
    public function createTask(User $actor, Appeal $appeal, array $taskData, array $assigneeIds = []): Task
    {
        $this->authorization->authorize($actor, 'appeals.close', $appeal);

        return DB::transaction(function () use ($actor, $appeal, $taskData, $assigneeIds): Task {
            $task = $this->tasks->create($actor, [
                'subject_person_id' => $appeal->person_id,
                'org_unit_id' => $appeal->org_unit_id,
                'territory_id' => $appeal->territory_id,
                ...$taskData,
            ], $assigneeIds);
            $this->attach($appeal, $task);

            return $task;
        });
    }

    public function linkTask(User $actor, Appeal $appeal, Task $task): void
    {
        $this->authorization->authorize($actor, 'appeals.close', $appeal);
        $this->authorization->authorize($actor, 'tasks.read', $task);
        $this->attach($appeal, $task);
    }

    private function attach(Appeal $appeal, Task $task): void
    {
        if ($appeal->tasks()->whereKey($task->id)->exists()) {
            return;
        }
        $appeal->tasks()->attach($task->id);
        $this->journal->record('crm.appeal.task_linked', $appeal, [], ['task_id' => $task->id]);
    }

    private function ensureActiveUser(int $personId): void
    {
        $user = User::query()->where('person_id', $personId)->first();
        if ($user === null || ! $user->isActive()) {
            throw CrmRuleViolation::because('responsible_not_active_user');
        }
    }

    private function notifyResponsible(Appeal $appeal, User $actor): void
    {
        if ($appeal->responsible_person_id !== null && $appeal->responsible_person_id !== $actor->person_id) {
            $appeal->responsible?->user?->notify(new CrmNotice(CrmNotice::APPEAL_ASSIGNED, $appeal->number.' · '.$appeal->title, '/admin/appeals/'.$appeal->id));
        }
    }
}
