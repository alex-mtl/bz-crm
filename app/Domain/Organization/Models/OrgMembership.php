<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The person's one current unit and their stored direct manager (Д-11).
 * manager_set_manually = the unit head changed it; such people do not follow a head change.
 *
 * @property int $person_id
 * @property int $org_unit_id
 * @property string|null $position
 * @property int|null $manager_person_id
 * @property bool $manager_set_manually
 * @property Carbon $joined_at
 * @property-read Person $person
 * @property-read OrgUnit $unit
 * @property-read Person|null $manager
 */
class OrgMembership extends Model
{
    protected $primaryKey = 'person_id';

    public $incrementing = false;

    protected $fillable = ['person_id', 'org_unit_id', 'position', 'manager_person_id', 'manager_set_manually', 'joined_at'];

    protected function casts(): array
    {
        return ['manager_set_manually' => 'boolean', 'joined_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<OrgUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'manager_person_id');
    }
}
