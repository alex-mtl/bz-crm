<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A person shares where they are — by their own decision, for a limited time, until they stop it (Д-22).
 *
 * @property int $id
 * @property int $person_id
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property Carbon|null $stopped_at
 * @property string|null $last_latitude
 * @property string|null $last_longitude
 * @property int|null $last_accuracy
 * @property Carbon|null $last_point_at
 * @property-read Person $person
 */
class LocationShare extends Model
{
    protected $fillable = [
        'person_id', 'started_at', 'expires_at', 'stopped_at', 'last_latitude', 'last_longitude', 'last_accuracy', 'last_point_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime', 'expires_at' => 'datetime', 'stopped_at' => 'datetime', 'last_point_at' => 'datetime',
            'last_accuracy' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return HasMany<LocationPoint, $this>
     */
    public function points(): HasMany
    {
        return $this->hasMany(LocationPoint::class);
    }

    /**
     * Going on right now: not stopped and not run out.
     *
     * @param  Builder<LocationShare>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull($query->getModel()->qualifyColumn('stopped_at'))
            ->where($query->getModel()->qualifyColumn('expires_at'), '>', now());
    }

    public function isLive(): bool
    {
        return $this->stopped_at === null && $this->expires_at->isFuture();
    }

    public function endedAt(): Carbon
    {
        return $this->stopped_at ?? $this->expires_at;
    }
}
