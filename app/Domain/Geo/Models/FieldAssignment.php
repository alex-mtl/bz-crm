<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An agitator answers for a house, or for every house of a territory (ФО §6.11: "2–3 дома, или участок, или
 * сектор"). Ended assignments stay as history.
 *
 * @property int $id
 * @property int $person_id
 * @property int|null $house_id
 * @property int|null $territory_id
 * @property int|null $assigned_by_person_id
 * @property Carbon $assigned_at
 * @property Carbon|null $ended_at
 * @property int|null $ended_by_person_id
 * @property-read Person $person
 * @property-read House|null $house
 * @property-read Territory|null $territory
 */
class FieldAssignment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'person_id', 'house_id', 'territory_id', 'assigned_by_person_id', 'assigned_at', 'ended_at', 'ended_by_person_id',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<House, $this>
     */
    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    /**
     * @return BelongsTo<Territory, $this>
     */
    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    /**
     * @param  Builder<FieldAssignment>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull($query->getModel()->qualifyColumn('ended_at'));
    }
}
