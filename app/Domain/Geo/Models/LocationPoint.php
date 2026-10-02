<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A point of a track — of a person sharing their location or of a vehicle's tracker. Kept for a limited time.
 *
 * @property int $id
 * @property int|null $location_share_id
 * @property int|null $vehicle_id
 * @property string $latitude
 * @property string $longitude
 * @property int|null $accuracy
 * @property Carbon $recorded_at
 */
class LocationPoint extends Model
{
    public $timestamps = false;

    protected $fillable = ['location_share_id', 'vehicle_id', 'latitude', 'longitude', 'accuracy', 'recorded_at'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'accuracy' => 'integer'];
    }
}
