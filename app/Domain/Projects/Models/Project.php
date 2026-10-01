<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A project (ФО §6.8.1). Lifecycle status and health are separate axes (ФО §6.8.4).
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $manager_person_id
 * @property int|null $org_unit_id
 * @property int|null $territory_id
 * @property string $visibility members | organization
 * @property string $status draft | not_started | in_progress | on_hold | completed | canceled
 * @property string $health on_track | at_risk | off_track
 * @property string $structure phases | flat
 * @property bool $strict_phases
 * @property Carbon|null $starts_on
 * @property Carbon|null $due_on
 * @property string|null $budget_plan
 * @property string|null $budget_fact
 * @property int|null $template_id
 * @property int|null $created_by_user_id
 * @property Carbon|null $archived_at
 * @property-read Person $manager
 */
class Project extends Model
{
    public const array STATUSES = ['draft', 'not_started', 'in_progress', 'on_hold', 'completed', 'canceled'];

    public const array HEALTH = ['on_track', 'at_risk', 'off_track'];

    protected $fillable = [
        'name', 'description', 'manager_person_id', 'org_unit_id', 'territory_id', 'visibility', 'status', 'health', 'structure',
        'strict_phases', 'starts_on', 'due_on', 'budget_plan', 'budget_fact', 'template_id', 'created_by_user_id', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'strict_phases' => 'boolean',
            'starts_on' => 'date',
            'due_on' => 'date',
            'budget_plan' => 'decimal:2',
            'budget_fact' => 'decimal:2',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'manager_person_id');
    }

    /**
     * @return HasMany<ProjectPhase, $this>
     */
    public function phases(): HasMany
    {
        return $this->hasMany(ProjectPhase::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<ProjectMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * @return HasMany<ProjectResource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(ProjectResource::class);
    }
}
