<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An assignee (1..N) or a watcher of a task — always a person with an active account (Д-15).
 *
 * @property int $id
 * @property int $task_id
 * @property int $person_id
 * @property string $role
 * @property-read Person $person
 */
class TaskPerson extends Model
{
    public const string ASSIGNEE = 'assignee';

    public const string WATCHER = 'watcher';

    protected $table = 'task_people';

    protected $fillable = ['task_id', 'person_id', 'role'];

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
