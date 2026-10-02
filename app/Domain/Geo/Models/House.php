<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use App\Domain\CRM\Models\Appeal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A house inside a territory (ФО §6.11). What a person may do with it is decided by FieldAccess only.
 *
 * @property int $id
 * @property int $address_id
 * @property int $territory_id
 * @property string $type_code
 * @property int|null $entrances
 * @property int|null $floors
 * @property int|null $residents_count
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $description
 * @property int|null $created_by_person_id
 * @property Carbon|null $archived_at
 * @property-read Address $address
 * @property-read Territory $territory
 */
class House extends Model
{
    public const string APARTMENT_BUILDING = 'apartment_building';

    public const string PRIVATE_HOUSE = 'private_house';

    protected $fillable = [
        'address_id', 'territory_id', 'type_code', 'entrances', 'floors', 'residents_count', 'latitude', 'longitude',
        'description', 'created_by_person_id', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'entrances' => 'integer',
            'floors' => 'integer',
            'residents_count' => 'integer',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * @return BelongsTo<Territory, $this>
     */
    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    /**
     * @return HasMany<Apartment, $this>
     */
    public function apartments(): HasMany
    {
        return $this->hasMany(Apartment::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<Visit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /**
     * @return HasMany<FieldAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(FieldAssignment::class);
    }

    /**
     * @return BelongsToMany<Appeal, $this>
     */
    public function appeals(): BelongsToMany
    {
        return $this->belongsToMany(Appeal::class, 'house_appeals');
    }

    /**
     * @param  Builder<House>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull($query->getModel()->qualifyColumn('archived_at'));
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** "str. Ismail 12" — the street as the directory spells it. */
    public function label(): string
    {
        return trim($this->address->street->name.' '.$this->address->number);
    }
}
