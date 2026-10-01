<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Every move of a lead: between stages, lost, frozen, returned (ТЗ §28).
 *
 * @property int $id
 * @property int $lead_id
 * @property int|null $from_stage_id
 * @property int|null $to_stage_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $moved_by_person_id
 * @property string|null $note
 * @property Carbon $created_at
 */
class LeadStageHistory extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'lead_stage_history';

    protected $fillable = ['lead_id', 'from_stage_id', 'to_stage_id', 'from_status', 'to_status', 'moved_by_person_id', 'note'];
}
