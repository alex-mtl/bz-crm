<?php

declare(strict_types=1);

namespace App\Domain\Catalogs;

use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use Illuminate\Support\ServiceProvider;

final class CatalogsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CatalogRegistry::class);
    }

    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('catalogs.item.created', EventCategory::Data),
            new EventType('catalogs.item.updated', EventCategory::Data),
            new EventType('catalogs.item.deactivated', EventCategory::Data, EventSeverity::Notice),
            new EventType('catalogs.item.reactivated', EventCategory::Data),
            new EventType('catalogs.item.merged', EventCategory::Data, EventSeverity::Notice),
            new EventType('catalogs.proposal.submitted', EventCategory::Business),
            new EventType('catalogs.proposal.approved', EventCategory::Business),
            new EventType('catalogs.proposal.rejected', EventCategory::Business),
            new EventType('catalogs.reference_data.imported', EventCategory::Admin),
        );
    }
}
