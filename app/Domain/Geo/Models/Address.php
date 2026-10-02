<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A street and a number — the unique place a house stands at.
 *
 * @property int $id
 * @property int $street_id
 * @property string $number
 * @property string $normalized_number
 * @property-read Street $street
 */
class Address extends Model
{
    protected $fillable = ['street_id', 'number', 'normalized_number'];

    /**
     * @return BelongsTo<Street, $this>
     */
    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class);
    }

    /**
     * @return HasOne<House, $this>
     */
    public function house(): HasOne
    {
        return $this->hasOne(House::class);
    }
}
