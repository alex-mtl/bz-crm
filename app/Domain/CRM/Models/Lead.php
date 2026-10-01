<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lead = a person + a position in a pipeline + a responsible + history (ФО §6.9.2). It references a Person,
 * it is not another Person (ТЗ §26).
 *
 * @property int $id
 * @property int $pipeline_id
 * @property int $stage_id
 * @property int $person_id
 * @property string|null $title
 * @property int|null $responsible_person_id
 * @property int|null $org_unit_id
 * @property int|null $territory_id
 * @property string $status
 * @property string|null $lost_reason_code
 * @property string|null $status_note
 * @property Carbon|null $frozen_until
 * @property string|null $source_code
 * @property int|null $created_by_person_id
 * @property Carbon|null $stage_entered_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $stage_due_at
 * @property int|null $import_batch_id
 * @property Carbon $created_at
 * @property-read Pipeline $pipeline
 * @property-read PipelineStage $stage
 * @property-read Person $person
 * @property-read Person|null $responsible
 */
class Lead extends Model
{
    public const string OPEN = 'open';

    public const string WON = 'won';

    public const string LOST = 'lost';

    public const string FROZEN = 'frozen';

    protected $fillable = [
        'pipeline_id', 'stage_id', 'person_id', 'title', 'responsible_person_id', 'org_unit_id', 'territory_id', 'status',
        'lost_reason_code', 'status_note', 'frozen_until', 'source_code', 'created_by_person_id', 'stage_entered_at', 'closed_at', 'import_batch_id', 'stage_due_at',
    ];

    protected function casts(): array
    {
        return ['frozen_until' => 'date', 'stage_entered_at' => 'datetime', 'closed_at' => 'datetime', 'stage_due_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /**
     * @return BelongsTo<PipelineStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
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
     * @return HasMany<LeadStageHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(LeadStageHistory::class)->orderBy('id');
    }

    /**
     * Too long in the stage (Д-22): computed, never stored. Only a lead in work can be late.
     */
    public function isStageOverdue(): bool
    {
        return $this->status === self::OPEN && $this->stage_due_at !== null && $this->stage_due_at->isPast();
    }

    /**
     * @param  Builder<Lead>  $query
     */
    public function scopeStageOverdue(Builder $query): void
    {
        $query->where('status', self::OPEN)->whereNotNull('stage_due_at')->where('stage_due_at', '<', now());
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::WON, self::LOST], true);
    }
}
