<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A flat of a house — or the single household of a private house. Holds the state after the last visit.
 *
 * @property int $id
 * @property int $house_id
 * @property string $number
 * @property int|null $entrance
 * @property int|null $floor
 * @property int $sort_order
 * @property string $status_code
 * @property int $attempts
 * @property Carbon|null $last_visit_at
 * @property int|null $last_visit_person_id
 * @property Carbon|null $next_visit_on
 * @property-read House $house
 * @property-read Person|null $lastVisitor
 */
class Apartment extends Model
{
    public const string NOT_VISITED = 'not_visited';

    public const string NOT_HOME = 'not_home';

    protected $fillable = [
        'house_id', 'number', 'entrance', 'floor', 'sort_order', 'status_code', 'attempts', 'last_visit_at',
        'last_visit_person_id', 'next_visit_on',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status_code' => self::NOT_VISITED, 'attempts' => 0, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'entrance' => 'integer',
            'floor' => 'integer',
            'sort_order' => 'integer',
            'attempts' => 'integer',
            'last_visit_at' => 'datetime',
            'next_visit_on' => 'date',
        ];
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
    public function lastVisitor(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'last_visit_person_id');
    }

    /**
     * @return HasMany<Visit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /**
     * @return HasMany<ApartmentNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ApartmentNote::class);
    }
}
