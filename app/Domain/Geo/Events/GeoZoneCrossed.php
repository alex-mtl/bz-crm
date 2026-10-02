<?php

declare(strict_types=1);

namespace App\Domain\Geo\Events;

use App\Domain\Geo\Models\GeoZoneCrossing;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A participant or a vehicle entered or left a geozone — a structured event for reports, notifications and,
 * later, automation (Д-22).
 */
final class GeoZoneCrossed
{
    use Dispatchable;

    public function __construct(public readonly GeoZoneCrossing $crossing) {}
}
