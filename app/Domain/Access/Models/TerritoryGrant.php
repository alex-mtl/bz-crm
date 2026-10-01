<?php

declare(strict_types=1);

namespace App\Domain\Access\Models;

use App\Domain\Geo\Models\Territory;
use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An additional territory given to a person (Д-3): who gave it, why, until when; revocation and expiry kept.
 *
 * @property int $id
 * @property int $person_id
 * @property int $territory_id
 * @property int|null $granted_by_user_id
 * @property string $reason
 * @property Carbon $granted_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason revoked | expired
 * @property int|null $ended_by_user_id
 * @property string|null $end_comment
 * @property-read Territory $territory
 * @property-read Person $person
 */
class TerritoryGrant extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'person_id', 'territory_id', 'granted_by_user_id', 'reason', 'granted_at', 'expires_at',
        'ended_at', 'end_reason', 'ended_by_user_id', 'end_comment',
    ];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'expires_at' => 'datetime', 'ended_at' => 'datetime'];
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

    /**
     * Not revoked and not past its end date — even before the scheduler records the expiry.
     *
     * @param  Builder<TerritoryGrant>  $query
     */
    public function scopeInEffect(Builder $query): void
    {
        $query->whereNull('ended_at')->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function state(): string
    {
        return match (true) {
            $this->end_reason !== null => $this->end_reason,
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }
}
