<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Hours an assignee spent on a task (ФО §6.8.2).
 *
 * @property int $id
 * @property int $task_id
 * @property int $person_id
 * @property int $minutes
 * @property Carbon $spent_on
 * @property string|null $note
 * @property-read Person $person
 */
class TimeEntry extends Model
{
    protected $table = 'task_time_entries';

    protected $fillable = ['task_id', 'person_id', 'minutes', 'spent_on', 'note'];

    protected function casts(): array
    {
        return ['spent_on' => 'date'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
