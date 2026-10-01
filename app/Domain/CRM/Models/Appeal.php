<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use App\Domain\People\Models\Person;
use App\Domain\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * An appeal (ФО §6.9.3): a request from the site, a complaint of a resident, a request for help.
 * Life cycle: new → in progress → done / rejected, with a responsible and a deadline.
 *
 * @property int $id
 * @property string $number
 * @property string $title
 * @property string|null $body
 * @property string $type_code
 * @property string|null $source_code
 * @property string $status
 * @property int|null $person_id
 * @property int|null $responsible_person_id
 * @property int|null $org_unit_id
 * @property int|null $territory_id
 * @property Carbon|null $due_at
 * @property string|null $resolution
 * @property Carbon|null $closed_at
 * @property string $priority_code
 * @property Carbon|null $first_response_due_at
 * @property Carbon|null $first_responded_at
 * @property int|null $created_by_person_id
 * @property Carbon $created_at
 * @property-read Person|null $person
 * @property-read Person|null $responsible
 */
class Appeal extends Model
{
    public const string NEW = 'new';

    public const string IN_PROGRESS = 'in_progress';

    public const string DONE = 'done';

    public const string REJECTED = 'rejected';

    public const array STATUSES = [self::NEW, self::IN_PROGRESS, self::DONE, self::REJECTED];

    protected $fillable = [
        'number', 'title', 'body', 'type_code', 'source_code', 'status', 'person_id', 'responsible_person_id',
        'org_unit_id', 'territory_id', 'due_at', 'resolution', 'closed_at', 'created_by_person_id',
        'priority_code', 'first_response_due_at', 'first_responded_at',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'closed_at' => 'datetime', 'first_response_due_at' => 'datetime', 'first_responded_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'responsible_person_id');
    }

    /**
     * @return BelongsToMany<Task, $this>
     */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'appeal_tasks');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::DONE, self::REJECTED], true);
    }

    /**
     * Overdue is computed, never stored.
     */
    public function isOverdue(): bool
    {
        return ! $this->isClosed() && $this->due_at !== null && $this->due_at->isPast();
    }

    /**
     * Nobody has taken the appeal into work in time (Д-22). Computed, never stored.
     */
    public function isFirstResponseOverdue(): bool
    {
        return $this->status === self::NEW && $this->first_response_due_at !== null && $this->first_response_due_at->isPast();
    }

    /**
     * @param  Builder<Appeal>  $query
     */
    public function scopeFirstResponseOverdue(Builder $query): void
    {
        $query->where('status', self::NEW)->whereNotNull('first_response_due_at')->where('first_response_due_at', '<', now());
    }

    /**
     * @param  Builder<Appeal>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->whereIn('status', [self::NEW, self::IN_PROGRESS])->whereNotNull('due_at')->where('due_at', '<', now());
    }
}
