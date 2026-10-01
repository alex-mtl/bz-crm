<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A project phase with its deadline, responsible person and budget (ФО §6.8.1).
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property int $sort_order
 * @property string $status not_started | in_progress | completed | canceled
 * @property Carbon|null $starts_on
 * @property Carbon|null $due_on
 * @property int|null $responsible_person_id
 * @property string|null $budget_plan
 * @property string|null $budget_fact
 * @property-read Project $project
 * @property-read Person|null $responsible
 */
class ProjectPhase extends Model
{
    public const array STATUSES = ['not_started', 'in_progress', 'completed', 'canceled'];

    protected $fillable = ['project_id', 'name', 'sort_order', 'status', 'starts_on', 'due_on', 'responsible_person_id', 'budget_plan', 'budget_fact'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'due_on' => 'date', 'budget_plan' => 'decimal:2', 'budget_fact' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'responsible_person_id');
    }

    /**
     * @return BelongsToMany<ProjectPhase, $this>
     */
    public function dependsOn(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'project_dependencies', 'phase_id', 'depends_on_phase_id');
    }
}
