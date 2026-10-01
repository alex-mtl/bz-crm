<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $territory_id
 * @property int $person_id
 * @property int|null $assigned_by_user_id
 * @property Carbon $assigned_at
 * @property-read Territory $territory
 * @property-read Person $person
 */
class TerritoryResponsible extends Model
{
    public $timestamps = false;

    protected $fillable = ['territory_id', 'person_id', 'assigned_by_user_id', 'assigned_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Territory, $this>
     */
    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
