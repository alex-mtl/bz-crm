<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something bound to a geozone — an event for now (ФО §6.11: "привязываются события, отчёты и уведомления").
 *
 * @property int $id
 * @property int $geo_zone_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $origin
 * @property int|null $linked_by_person_id
 * @property-read GeoZone $zone
 */
class GeoZoneLink extends Model
{
    public const string AUTO = 'auto';

    public const string MANUAL = 'manual';

    public const string EVENT = 'event';

    public const UPDATED_AT = null;

    protected $fillable = ['geo_zone_id', 'subject_type', 'subject_id', 'origin', 'linked_by_person_id'];

    /**
     * @return BelongsTo<GeoZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(GeoZone::class, 'geo_zone_id');
    }
}
