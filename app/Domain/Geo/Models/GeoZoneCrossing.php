<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A participant or a vehicle entered or left a geozone — a structured event for reports and notifications.
 *
 * @property int $id
 * @property int $geo_zone_id
 * @property string $mover_type
 * @property int $mover_id
 * @property string $direction
 * @property Carbon $occurred_at
 * @property string $latitude
 * @property string $longitude
 * @property-read GeoZone $zone
 */
class GeoZoneCrossing extends Model
{
    public const string PERSON = 'person';

    public const string VEHICLE = 'vehicle';

    public const string ENTER = 'enter';

    public const string EXIT = 'exit';

    public $timestamps = false;

    protected $fillable = ['geo_zone_id', 'mover_type', 'mover_id', 'direction', 'occurred_at', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<GeoZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(GeoZone::class, 'geo_zone_id');
    }
}
