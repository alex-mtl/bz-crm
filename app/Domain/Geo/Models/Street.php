<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A street of the address directory (ФО §6.11): one row however its name is typed — see AddressNormalizer.
 *
 * @property int $id
 * @property int $territory_id
 * @property string $type_code
 * @property string $name
 * @property string $normalized
 * @property-read Territory $territory
 */
class Street extends Model
{
    protected $fillable = ['territory_id', 'type_code', 'name', 'normalized'];

    /**
     * @return BelongsTo<Territory, $this>
     */
    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    /**
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }
}
