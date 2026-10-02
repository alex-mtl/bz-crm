<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One visit to a flat: who, when, with what result. A visit that came from the offline queue carries the id of
 * its operation — the same operation never makes a second visit (ТЗ §34).
 *
 * @property int $id
 * @property int $apartment_id
 * @property int $house_id
 * @property int $person_id
 * @property string $status_code
 * @property int $attempt_no
 * @property Carbon $visited_at
 * @property Carbon|null $next_visit_on
 * @property int|null $task_id
 * @property string|null $operation_id
 * @property string $source
 * @property-read Apartment $apartment
 * @property-read House $house
 * @property-read Person $person
 */
class Visit extends Model
{
    public const string ONLINE = 'online';

    public const string OFFLINE = 'offline';

    protected $fillable = [
        'apartment_id', 'house_id', 'person_id', 'status_code', 'attempt_no', 'visited_at', 'next_visit_on', 'task_id',
        'operation_id', 'source',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['source' => self::ONLINE, 'attempt_no' => 1];

    protected function casts(): array
    {
        return ['visited_at' => 'datetime', 'next_visit_on' => 'date', 'attempt_no' => 'integer'];
    }

    /**
     * @return BelongsTo<Apartment, $this>
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    /**
     * @return BelongsTo<House, $this>
     */
    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
