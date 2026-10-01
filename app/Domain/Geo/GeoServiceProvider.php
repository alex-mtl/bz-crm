<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Geo\Console\ImportTerritoriesCommand;
use Illuminate\Support\ServiceProvider;

final class GeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeoService::class);
    }

    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('geo.territories.imported', EventCategory::Admin, EventSeverity::Notice),
            new EventType('geo.territory.created', EventCategory::Admin),
            new EventType('geo.territory.updated', EventCategory::Admin),
            new EventType('geo.territory.responsible_assigned', EventCategory::Admin),
            new EventType('geo.territory.responsible_removed', EventCategory::Admin),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([ImportTerritoriesCommand::class]);
        }
    }
}
