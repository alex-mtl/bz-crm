<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A task (ФО §6.8.2, ТЗ §24). Deletion is a mark: history stays.
 *
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string $type_code
 * @property string $status_code
 * @property string $priority_code
 * @property int $creator_person_id
 * @property int|null $project_id
 * @property int|null $phase_id
 * @property int|null $parent_id
 * @property int $depth
 * @property int|null $org_unit_id
 * @property int|null $territory_id
 * @property int|null $subject_person_id
 * @property Carbon|null $due_at
 * @property int|null $estimate_minutes
 * @property string|null $blocked_reason
 * @property string|null $blocked_by
 * @property Carbon|null $on_hold_until
 * @property string|null $cancel_reason
 * @property string|null $status_before
 * @property array{freq: string, interval: int}|null $recurrence
 * @property int|null $recurrence_of_id
 * @property Carbon|null $completed_at
 * @property Carbon|null $escalated_at
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property-read Person $creator
 * @property-read Person|null $subject
 * @property-read Task|null $parent
 */
class Task extends Model
{
    public const array CLOSED = ['done', 'canceled'];

    protected $fillable = [
        'title', 'description', 'type_code', 'status_code', 'priority_code', 'creator_person_id', 'project_id', 'phase_id',
        'parent_id', 'depth', 'org_unit_id', 'territory_id', 'subject_person_id', 'due_at', 'estimate_minutes',
        'blocked_reason', 'blocked_by', 'on_hold_until', 'cancel_reason', 'status_before', 'recurrence', 'recurrence_of_id',
        'completed_at', 'escalated_at', 'deleted_at', 'deleted_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'on_hold_until' => 'date',
            'recurrence' => 'array',
            'completed_at' => 'datetime',
            'escalated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'depth' => 'integer',
        ];
    }

    /**
     * ФО §6.8.4: overdue is a computed flag, never a status.
     */
    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast() && ! in_array($this->status_code, self::CLOSED, true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status_code, self::CLOSED, true);
    }

    /**
     * @param  Builder<Task>  $query
     */
    public function scopeNotDeleted(Builder $query): void
    {
        $query->whereNull($query->getModel()->qualifyColumn('deleted_at'));
    }

    /**
     * @param  Builder<Task>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->whereNotNull('due_at')->where('due_at', '<', now())->whereNotIn('status_code', self::CLOSED);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'creator_person_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'subject_person_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsToMany<Person, $this>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'task_people')->wherePivot('role', TaskPerson::ASSIGNEE)->withTimestamps();
    }

    /**
     * @return BelongsToMany<Person, $this>
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'task_people')->wherePivot('role', TaskPerson::WATCHER)->withTimestamps();
    }

    /**
     * @return HasMany<TaskPerson, $this>
     */
    public function people(): HasMany
    {
        return $this->hasMany(TaskPerson::class);
    }

    /**
     * @return HasMany<ChecklistItem, $this>
     */
    public function checklist(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return BelongsToMany<Task, $this>
     */
    public function dependsOn(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'task_id', 'depends_on_task_id');
    }

    public function hasPerson(int $personId, ?string $role = null): bool
    {
        return TaskPerson::query()->where('task_id', $this->id)->where('person_id', $personId)
            ->when($role !== null, fn (Builder $q) => $q->where('role', $role))->exists();
    }
}
