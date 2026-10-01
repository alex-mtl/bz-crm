<?php

declare(strict_types=1);

namespace App\Domain\CRM;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\Segment;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\Tasks\Actions\ManageTasks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Segments and saved filters (ФО §6.9.4). A personal filter belongs to its owner; a shared segment is seen by
 * everyone with segments.read. Membership is never stored: it is computed for the reader, inside the reader's
 * own people.read scope — two readers of one segment may rightly see different people.
 */
final readonly class Segments
{
    public const int MASS_TASK_LIMIT = 500;

    public function __construct(
        private AuthorizationService $authorization,
        private SegmentQuery $query,
        private ManageTasks $tasks,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, criteria: array<string, mixed>, visibility?: string}  $data
     */
    public function save(User $actor, array $data, ?Segment $segment = null): Segment
    {
        $visibility = $data['visibility'] ?? ($segment !== null ? $segment->visibility : Segment::PERSONAL);
        if (! in_array($visibility, [Segment::PERSONAL, Segment::SHARED], true)) {
            throw CrmRuleViolation::because('invalid_segment_visibility');
        }
        if ($segment !== null) {
            $this->ensureMayChange($actor, $segment);
        }
        if ($visibility === Segment::SHARED) {
            $this->authorization->authorize($actor, 'segments.manage');
        } else {
            // A saved filter is a view on people: it takes the right to see people at all.
            $this->authorization->authorize($actor, 'people.read');
        }
        $name = trim($data['name']);
        if ($name === '') {
            throw CrmRuleViolation::because('segment_name_required');
        }
        $criteria = $this->query->clean($data['criteria']);

        return DB::transaction(function () use ($actor, $segment, $name, $data, $criteria, $visibility): Segment {
            $created = $segment === null;
            $segment ??= new Segment(['owner_user_id' => $actor->id]);
            $segment->fill([
                'name' => $name,
                'description' => $data['description'] ?? $segment->description,
                'criteria' => $criteria,
                'visibility' => $visibility,
            ])->save();
            $this->journal->record($created ? 'crm.segment.created' : 'crm.segment.updated', $segment, [], [
                'name' => $name, 'visibility' => $visibility, 'criteria' => array_keys($criteria),
            ]);

            return $segment;
        });
    }

    public function delete(User $actor, Segment $segment): void
    {
        $this->ensureMayChange($actor, $segment);

        DB::transaction(function () use ($segment): void {
            $this->journal->record('crm.segment.deleted', $segment, ['name' => $segment->name, 'visibility' => $segment->visibility]);
            $segment->delete();
        });
    }

    /**
     * Segments the user may open: their own filters and, with segments.read, the shared ones.
     *
     * @return Builder<Segment>
     */
    public function visibleTo(User $user): Builder
    {
        $shared = $this->authorization->can($user, 'segments.read');

        return Segment::query()->where(fn (Builder $w) => $w
            ->where('owner_user_id', $user->id)
            ->when($shared, fn (Builder $q) => $q->orWhere('visibility', Segment::SHARED)));
    }

    public function maySee(User $user, Segment $segment): bool
    {
        return $segment->owner_user_id === $user->id
            || ($segment->visibility === Segment::SHARED && $this->authorization->can($user, 'segments.read'));
    }

    /**
     * People of the segment as this reader may see them.
     *
     * @return Builder<Person>
     */
    public function people(User $reader, Segment $segment): Builder
    {
        if (! $this->maySee($reader, $segment)) {
            throw new AuthorizationException(__('access.denied'));
        }

        return $this->preview($reader, $segment->criteria);
    }

    /**
     * The same for criteria not saved yet (building a filter).
     *
     * @param  array<string, mixed>  $criteria
     * @return Builder<Person>
     */
    public function preview(User $reader, array $criteria): Builder
    {
        return $this->authorization->scopeQuery($reader, 'people.read', $this->query->build($criteria));
    }

    /**
     * "Segments are used for mass tasks" (ФО §6.9.4): one task per person of the segment, the person being the
     * subject of the task (Д-15). Only people the actor sees get a task.
     *
     * @param  array<string, mixed>  $taskData  title, type_code, due_at, priority_code, description
     * @param  list<int>  $assigneeIds
     * @return int number of tasks created
     */
    public function createTasks(User $actor, Segment $segment, array $taskData, array $assigneeIds): int
    {
        $people = $this->people($actor, $segment)->orderBy('people.id')->limit(self::MASS_TASK_LIMIT + 1)->get();
        if ($people->count() > self::MASS_TASK_LIMIT) {
            throw CrmRuleViolation::because('segment_too_large_for_tasks', ['limit' => self::MASS_TASK_LIMIT]);
        }

        return DB::transaction(function () use ($actor, $segment, $people, $taskData, $assigneeIds): int {
            foreach ($people as $person) {
                $this->tasks->create($actor, [...$taskData, 'subject_person_id' => $person->id], $assigneeIds);
            }
            $this->journal->record('crm.segment.tasks_created', $segment, [], ['tasks' => $people->count()]);

            return $people->count();
        });
    }

    private function ensureMayChange(User $actor, Segment $segment): void
    {
        if ($segment->owner_user_id === $actor->id) {
            return;
        }
        // Someone else's shared segment: only who manages segments and can see its owner (their scope).
        if ($segment->visibility !== Segment::SHARED || ! $this->authorization->can($actor, 'segments.manage', $segment->owner->person)) {
            throw new AuthorizationException(__('access.denied'));
        }
    }
}
