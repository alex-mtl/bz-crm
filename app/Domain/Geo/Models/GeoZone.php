<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named outline with people who answer for it and things bound to it (ФО §6.11) — an object, not a picture.
 * The outline is read and written only through GeoService (ТЗ §32).
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $color
 * @property int|null $territory_id
 * @property string $geometry
 * @property string $min_latitude
 * @property string $max_latitude
 * @property string $min_longitude
 * @property string $max_longitude
 * @property bool $notify_events
 * @property bool $notify_crossings
 * @property int|null $created_by_person_id
 * @property Carbon|null $archived_at
 * @property-read Territory|null $territory
 */
class GeoZone extends Model
{
    protected $fillable = [
        'name', 'description', 'color', 'territory_id', 'geometry', 'min_latitude', 'max_latitude', 'min_longitude',
        'max_longitude', 'notify_events', 'notify_crossings', 'created_by_person_id', 'archived_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['color' => '#2563eb', 'notify_events' => true, 'notify_crossings' => true];

    protected function casts(): array
    {
        return ['notify_events' => 'boolean', 'notify_crossings' => 'boolean', 'archived_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Territory, $this>
     */
    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    /**
     * @return BelongsToMany<Person, $this>
     */
    public function responsibles(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'geo_zone_responsibles');
    }

    /**
     * @return HasMany<GeoZoneLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(GeoZoneLink::class);
    }

    /**
     * @return HasMany<GeoZoneCrossing, $this>
     */
    public function crossings(): HasMany
    {
        return $this->hasMany(GeoZoneCrossing::class);
    }

    /**
     * @param  Builder<GeoZone>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull($query->getModel()->qualifyColumn('archived_at'));
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
