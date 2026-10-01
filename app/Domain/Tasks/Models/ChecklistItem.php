<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A checklist item with its own responsible person (ФО §6.8.2).
 *
 * @property int $id
 * @property int $task_id
 * @property string $title
 * @property int|null $responsible_person_id
 * @property Carbon|null $done_at
 * @property int|null $done_by_person_id
 * @property int $sort_order
 * @property-read Person|null $responsible
 */
class ChecklistItem extends Model
{
    protected $table = 'task_checklist_items';

    protected $fillable = ['task_id', 'title', 'responsible_person_id', 'done_at', 'done_by_person_id', 'sort_order'];

    protected function casts(): array
    {
        return ['done_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'responsible_person_id');
    }
}
