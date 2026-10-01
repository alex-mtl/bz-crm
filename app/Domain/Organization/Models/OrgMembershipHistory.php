<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Past memberships: a transfer keeps where the person was and for how long (Д-11).
 *
 * @property int $id
 * @property int $person_id
 * @property int $org_unit_id
 * @property string|null $position
 * @property Carbon $joined_at
 * @property Carbon $left_at
 * @property string $left_reason
 * @property int|null $changed_by_user_id
 * @property-read OrgUnit $unit
 */
class OrgMembershipHistory extends Model
{
    protected $table = 'org_membership_history';

    public $timestamps = false;

    protected $fillable = ['person_id', 'org_unit_id', 'position', 'joined_at', 'left_at', 'left_reason', 'changed_by_user_id'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<OrgUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }
}
