<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use App\Domain\Organization\Models\OrgUnit;
use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A vehicle of the organization (ФО §6.11, Д-22): a card, a tracker with a key of its own, a history of movement.
 *
 * @property int $id
 * @property string $name
 * @property string|null $plate
 * @property string $type_code
 * @property int|null $org_unit_id
 * @property int|null $responsible_person_id
 * @property string|null $description
 * @property string|null $tracker_key_hash
 * @property Carbon|null $tracker_key_issued_at
 * @property string|null $last_latitude
 * @property string|null $last_longitude
 * @property Carbon|null $last_point_at
 * @property Carbon|null $archived_at
 * @property-read OrgUnit|null $unit
 * @property-read Person|null $responsible
 */
class Vehicle extends Model
{
    protected $fillable = [
        'name', 'plate', 'type_code', 'org_unit_id', 'responsible_person_id', 'description', 'tracker_key_hash',
        'tracker_key_issued_at', 'last_latitude', 'last_longitude', 'last_point_at', 'archived_at',
    ];

    protected $hidden = ['tracker_key_hash'];

    protected function casts(): array
    {
        return ['tracker_key_issued_at' => 'datetime', 'last_point_at' => 'datetime', 'archived_at' => 'datetime'];
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
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'responsible_person_id');
    }

    /**
     * @return HasMany<LocationPoint, $this>
     */
    public function points(): HasMany
    {
        return $this->hasMany(LocationPoint::class);
    }

    /**
     * @param  Builder<Vehicle>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull($query->getModel()->qualifyColumn('archived_at'));
    }

    public function hasTracker(): bool
    {
        return $this->tracker_key_hash !== null;
    }
}
