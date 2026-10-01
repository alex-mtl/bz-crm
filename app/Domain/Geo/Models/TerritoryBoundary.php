<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Outline of a territory (GeoJSON). Read and written only through GeoService (ТЗ §32).
 *
 * @property int $territory_id
 * @property string $geojson
 * @property string $source
 */
class TerritoryBoundary extends Model
{
    protected $primaryKey = 'territory_id';

    public $incrementing = false;

    protected $fillable = ['territory_id', 'geojson', 'source'];
}
