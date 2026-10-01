<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A resource of a project, phase or task — people, money, transport, materials — plan vs fact (ФО §6.8.5).
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $phase_id
 * @property int|null $task_id
 * @property string $kind_code
 * @property string $description
 * @property int|null $person_id
 * @property string|null $plan_amount
 * @property string|null $fact_amount
 * @property-read Person|null $person
 */
class ProjectResource extends Model
{
    protected $fillable = ['project_id', 'phase_id', 'task_id', 'kind_code', 'description', 'person_id', 'plan_amount', 'fact_amount'];

    protected function casts(): array
    {
        return ['plan_amount' => 'decimal:2', 'fact_amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
