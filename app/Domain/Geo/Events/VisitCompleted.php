<?php

declare(strict_types=1);

namespace App\Domain\Geo\Events;

use App\Domain\Geo\Models\Visit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A visit to a flat was recorded (ТЗ §44). Other modules react to it — gamification counts confirmed visits.
 */
final class VisitCompleted
{
    use Dispatchable;

    public function __construct(public readonly Visit $visit) {}
}
